/* Tidemark tracker — Plausible-compatible. Variants are selected by file name: script.<ext>.js */
(function () {
  'use strict';
  var location = window.location, doc = window.document;
  var el = doc.currentScript;
  if (!el) { return; }
  var src = el.src || '';
  var file = src.split('/').pop().split('?')[0];
  var mods = file.replace(/\.js$/, '').split('.').slice(1);
  var has = function (m) { return mods.indexOf(m) !== -1; };
  // A per-site file name (a 10-character code in place of "script") posts to the matching /e/<code> address; the classic
  // script.js keeps posting to /api/event, so sites that already have the snippet are unaffected.
  var origin = src.replace(/\/js\/[^/]*$/, '');
  var alias = file.split('.')[0];
  var endpoint = el.getAttribute('data-api') || (/^[a-f0-9]{10}$/.test(alias) ? origin + '/e/' + alias : origin + '/api/event');
  var domain = el.getAttribute('data-domain');
  var hashMode = has('hash');
  var local = has('local');

  function warn(reason) { if (window.console) { console.warn('Ignoring event: ' + reason); } }

  function send(name, options) {
    options = options || {};
    if (!local && (/^localhost$|^127(\.[0-9]+){0,2}\.[0-9]+$|^\[::1?\]$/.test(location.hostname) || location.protocol === 'file:')) {
      return warn('localhost');
    }
    if (window._phantom || window.__nightmare || window.navigator.webdriver || window.Cypress) { return; }
    try { if (window.localStorage.plausible_ignore === 'true') { return warn('localStorage flag'); } } catch (e) { /* ignore */ }

    var payload = { n: name, u: options.u || location.href, d: domain, r: doc.referrer || null, w: window.innerWidth };
    if (options.props) { payload.p = options.props; }
    if (options.revenue) { payload.$ = options.revenue; }
    if (hashMode && (!options.u || options.hash)) { payload.h = 1; }
    if (options.interactive === false) { payload.i = 0; }

    // a measurement sent as the page is closing goes out as a beacon, which the browser finishes after the page is gone
    if (options.beacon && window.navigator.sendBeacon) {
      try { if (window.navigator.sendBeacon(endpoint, JSON.stringify(payload))) { return; } } catch (e) { /* fall back to a request */ }
    }

    var req = new XMLHttpRequest();
    req.open('POST', endpoint, true);
    req.setRequestHeader('Content-Type', 'text/plain');
    req.send(JSON.stringify(payload));
    req.onreadystatechange = function () {
      if (req.readyState === 4 && options.callback) { options.callback({ status: req.status }); }
    };
  }

  var queue = (window.plausible && window.plausible.q) || [];
  window.plausible = send;

  // Engagement (script.engagement.js): how far down the page the visitor scrolled and how long they were actively on it, sent as a
  // non-interactive "Engagement" event (so it never changes bounce rate) whenever the page is left or its tab hidden. Each send carries the
  // totals so far and the server keeps only the latest for the page view. Only two coarse buckets are sent, no element, text or position.
  // Active time stops after a minute without scrolling, moving, typing or touching.
  var engagement = has('engagement') ? (function () {
    var IDLE = 60000, url, hash, depthMax, activeMs, since, idle, lastTick, last;
    function scrollPercent() {
      var d = doc.documentElement, b = doc.body;
      var total = Math.max(d.scrollHeight, b ? b.scrollHeight : 0, 1);
      return Math.min(100, Math.round(((window.pageYOffset || d.scrollTop || 0) + (window.innerHeight || d.clientHeight || 0)) / total * 100));
    }
    function pause() { if (since) { activeMs += Date.now() - since; since = 0; } clearTimeout(idle); }
    function active() {
      var t = Date.now();
      if (doc.visibilityState === 'hidden' || (since && t - lastTick < 1000)) { return; }
      lastTick = t;
      if (!since) { since = t; }
      clearTimeout(idle);
      idle = setTimeout(pause, IDLE);
    }
    function seen() { var p = scrollPercent(); if (p > depthMax) { depthMax = p; } }
    function reset() {
      url = location.href; hash = hashMode; activeMs = 0; since = 0; lastTick = 0; last = ''; depthMax = 0;
      clearTimeout(idle);
      seen(); active();
    }
    function flush() {
      seen(); pause();
      var s = activeMs / 1000;
      var props = { depth: depthMax >= 100 ? '100' : depthMax >= 75 ? '75' : depthMax >= 50 ? '50' : depthMax >= 25 ? '25' : '0', time: s < 10 ? '0-10s' : s < 30 ? '10-30s' : s < 60 ? '30-60s' : s < 180 ? '1-3m' : '3m+' };
      var key = props.depth + props.time;
      if (key === last) { return; }
      last = key;
      send('Engagement', { u: url, hash: hash, interactive: false, beacon: true, props: props });
    }
    window.addEventListener('scroll', function () { seen(); active(); }, { passive: true });
    ['mousemove', 'keydown', 'touchstart', 'click'].forEach(function (type) { doc.addEventListener(type, active, { passive: true, capture: true }); });
    doc.addEventListener('visibilitychange', function () { if (doc.visibilityState === 'hidden') { flush(); } else { active(); } });
    window.addEventListener('pagehide', flush);
    reset();
    return { reset: reset, flush: flush };
  })() : null;

  var lastPage;
  function page(options) {
    if (!hashMode && lastPage === location.pathname) { return; }
    if (engagement && lastPage !== undefined) { engagement.flush(); } // the page being left, still reported under its own address
    lastPage = location.pathname;
    send('pageview', options);
    if (engagement) { engagement.reset(); }
  }

  if (!has('manual')) {
    var history = window.history;
    if (history.pushState) {
      var push = history.pushState;
      history.pushState = function () { push.apply(this, arguments); page(); };
      window.addEventListener('popstate', function () { page(); });
    }
    if (hashMode) { window.addEventListener('hashchange', function () { page(); }); }

    var start = function () { page(); };
    if (doc.visibilityState === 'prerender') {
      doc.addEventListener('visibilitychange', function () { if (!lastPage && doc.visibilityState === 'visible') { start(); } });
    } else {
      start();
    }
  }

  for (var i = 0; i < queue.length; i++) { send.apply(this, queue[i]); }

  function closest(node, tag) {
    while (node && (!node.tagName || node.tagName.toLowerCase() !== tag)) { node = node.parentNode; }
    return node;
  }
  function middle(e) { return e.type === 'auxclick' && e.button !== 1; }
  function modified(e) { return e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0 && e.type === 'click'; }

  function followLink(e, a, name, props) {
    if (middle(e)) { return; }
    var go = function () { if (a.target && !a.target.match(/^_(self|parent|top)$/i)) { return; } location.href = a.href; };
    if (modified(e) || (a.target && !a.target.match(/^_(self|parent|top)$/i))) {
      send(name, { props: props });
    } else {
      var done = false;
      var finish = function () { if (!done) { done = true; go(); } };
      send(name, { props: props, callback: finish });
      setTimeout(finish, 1000);
      e.preventDefault();
    }
  }

  if (has('outbound-links') || has('file-downloads')) {
    var downloads = (el.getAttribute('file-types') || 'pdf,xlsx,docx,txt,rtf,csv,exe,key,pps,ppt,pptx,7z,pkg,rar,gz,zip,avi,mov,mp4,mpeg,wmv,midi,mp3,wav,wma,dmg').split(',');
    var linkClick = function (e) {
      if (e.type === 'auxclick' && e.button !== 1) { return; }
      var a = closest(e.target, 'a');
      if (!a || !a.href) { return; }
      if (has('outbound-links') && a.host !== location.host && /^https?:/.test(a.href)) {
        followLink(e, a, 'Outbound Link: Click', { url: a.href });
      } else if (has('file-downloads')) {
        var ext = (a.pathname.split('.').pop() || '').toLowerCase();
        if (a.pathname.indexOf('.') !== -1 && downloads.indexOf(ext) !== -1) {
          followLink(e, a, 'File Download', { url: a.href });
        }
      }
    };
    doc.addEventListener('click', linkClick);
    doc.addEventListener('auxclick', linkClick);
  }

  if (has('form-submissions')) {
    doc.addEventListener('submit', function (e) {
      var form = e.target;
      if (form && form.getAttribute && form.getAttribute('data-tidemark-ignore') === null) {
        send('Form: Submission', { props: { path: location.pathname } });
      }
    }, true);
  }

  if (has('tagged-events')) {
    var tagged = function (e) {
      var node = e.target;
      while (node && node !== doc) {
        var cls = (node.getAttribute && node.getAttribute('class')) || '';
        var m = cls.match(/(?:^|\s)plausible-event-name=(\S+)/);
        if (m) {
          var props = {};
          cls.split(/\s+/).forEach(function (c) {
            var p = c.match(/^plausible-event-(?!name=)(.+?)=(.+)$/);
            if (p) { props[p[1]] = p[2].replace(/\+/g, ' '); }
          });
          var amount = cls.match(/(?:^|\s)plausible-revenue-amount=(\S+)/), currency = cls.match(/(?:^|\s)plausible-revenue-currency=(\S+)/);
          send(m[1].replace(/\+/g, ' '), { props: props, revenue: amount && currency ? { amount: amount[1], currency: currency[1] } : undefined });
          return;
        }
        node = node.parentNode;
      }
    };
    doc.addEventListener('click', tagged);
  }
})();
