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
  var endpoint = el.getAttribute('data-api') || (src.replace(/\/js\/[^/]*$/, '') + '/api/event');
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
    if (hashMode && !options.u) { payload.h = 1; }

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

  var lastPage;
  function page(options) {
    if (!hashMode && lastPage === location.pathname) { return; }
    lastPage = location.pathname;
    send('pageview', options);
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
