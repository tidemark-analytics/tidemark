// Core helpers: preact/htm re-exports, API client, router, formatting, date-range + filter state.
import * as P from '../vendor/preact-htm.js';
import { brandTheme, BRAND_VARS } from './brand.js';
export const { h, html, render, useState, useEffect, useLayoutEffect, useRef, useMemo, useCallback, createContext, useContext, Component } = P;

export const BASE = window.SD.base;
export const UserCtx = createContext(null);

// ---------------------------------------------------------------------------------------------- API
export class ApiError extends Error {
  constructor(status, body) {
    super((body && (body.message || body.error)) || 'Request failed');
    this.status = status;
    this.body = body;
    this.errors = body && body.errors;
  }
}

/** Shared-link credentials: /share/{domain}?auth={slug}; the optional password is kept in memory only. */
export const share = { password: '' };
export const shareAuth = () => (/\/share\//.test(location.pathname) ? new URLSearchParams(location.search).get('auth') : null);

export async function api(path, { method = 'GET', body, params } = {}) {
  let url = BASE + '/api' + path;
  const q = new URLSearchParams();
  if (params) for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') q.set(k, v);
  const sid = shareAuth();
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (sid) { headers['X-Share-Auth'] = sid; if (share.password) headers['X-Share-Password'] = share.password; }
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData;
  if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json';
  const qs = q.toString();
  if (qs) url += (url.includes('?') ? '&' : '?') + qs;
  const res = await fetch(url, { method, headers, credentials: 'same-origin', body: body === undefined ? undefined : isForm ? body : JSON.stringify(body) });
  let data = null;
  try { data = await res.json(); } catch (e) { /* empty */ }
  if (!res.ok && res.status !== 202) {
    if (res.status === 401 && path !== '/me' && !shareAuth() && !(data && data.error === 'password_required') && !['/login', '/register'].includes(location.pathname.replace(BASE, ''))) {
      navigate('/login', { replace: true });
    }
    throw new ApiError(res.status, data);
  }
  if (res.status === 202) data = { ...data, _status: 202 };
  return data;
}

// -------------------------------------------------------------------------------------------- toasts
const toastListeners = new Set();
export function toast(message, kind = '') {
  const t = { id: Math.random(), message, kind };
  toastListeners.forEach((fn) => fn(t));
}
export function useToasts() {
  const [list, setList] = useState([]);
  useEffect(() => {
    const fn = (t) => {
      setList((l) => [...l, t]);
      setTimeout(() => setList((l) => l.filter((x) => x.id !== t.id)), 3800);
    };
    toastListeners.add(fn);
    return () => toastListeners.delete(fn);
  }, []);
  return list;
}

// -------------------------------------------------------------------------------------------- router
const routeListeners = new Set();
export const appPath = () => {
  let p = location.pathname;
  if (BASE && p.startsWith(BASE)) p = p.slice(BASE.length);
  return p || '/';
};
export function navigate(to, { replace = false } = {}) {
  const url = to.startsWith('http') || to.startsWith(BASE + '/') ? to : BASE + to;
  if (url === location.pathname + location.search) return;
  history[replace ? 'replaceState' : 'pushState']({}, '', url);
  routeListeners.forEach((fn) => fn());
  window.scrollTo(0, 0);
}
window.addEventListener('popstate', () => routeListeners.forEach((fn) => fn()));
document.addEventListener('click', (e) => {
  const a = e.target.closest && e.target.closest('a[data-link]');
  if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
  e.preventDefault();
  navigate(a.getAttribute('href').replace(BASE, '') || '/');
});

export function useLocation() {
  const [, set] = useState(0);
  useEffect(() => {
    const fn = () => set((n) => n + 1);
    routeListeners.add(fn);
    return () => routeListeners.delete(fn);
  }, []);
  return { path: appPath(), query: Object.fromEntries(new URLSearchParams(location.search)) };
}

export function setQuery(patch, { replace = true } = {}) {
  const q = new URLSearchParams(location.search);
  for (const [k, v] of Object.entries(patch)) {
    if (v === null || v === undefined || v === '') q.delete(k);
    else q.set(k, v);
  }
  const s = q.toString();
  navigate(appPath() + (s ? '?' + s : ''), { replace });
}

export const Link = ({ to, children, class: cls, ...rest }) => html`<a data-link href=${BASE + to} class=${cls} ...${rest}>${children}</a>`;

export function matchRoute(routes, path) {
  for (const [pattern, component] of routes) {
    const keys = [];
    const source = pattern.replace(/\/:([a-zA-Z]+)(\?)?/g, (m, k, opt) => { keys.push(k); return opt ? '(?:/([^/]+))?' : '/([^/]+)'; });
    const m = path.match(new RegExp('^' + source + '/?$'));
    if (m) return { component, params: Object.fromEntries(keys.map((k, i) => [k, m[i + 1]])) };
  }
  return null;
}

// ------------------------------------------------------------------------------------------- formatting
export const nf = new Intl.NumberFormat();
export const compact = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });
export const fmt = (n) => (n === null || n === undefined ? '—' : nf.format(Math.round(n * 100) / 100));
export const fmtShort = (n) => (n === null || n === undefined ? '—' : Math.abs(n) >= 10000 ? compact.format(n) : nf.format(Math.round(n * 100) / 100));
export const pct = (n, d = 0) => (n === null || n === undefined || !isFinite(n) ? '—' : (n * 100).toFixed(d) + '%');
export function dur(s) {
  s = Math.round(s || 0);
  if (s < 60) return s + 's';
  const m = Math.floor(s / 60);
  if (m < 60) return m + 'm ' + String(s % 60).padStart(2, '0') + 's';
  return Math.floor(m / 60) + 'h ' + String(m % 60).padStart(2, '0') + 'm';
}
/** A currency amount, shortened like 12.5K once it gets large. */
export function moneyShort(v, currency = 'USD') {
  try { return new Intl.NumberFormat(undefined, { style: 'currency', currency, ...(Math.abs(v || 0) >= 10000 ? { notation: 'compact', maximumFractionDigits: 1 } : {}) }).format(v || 0); } catch (e) { return (v || 0).toFixed(2); }
}
export function money(v, currency = 'USD') {
  try { return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(v || 0); } catch (e) { return (v || 0).toFixed(2); }
}
export const flag = (cc) => (cc && cc.length === 2 ? String.fromCodePoint(...[...cc.toUpperCase()].map((c) => 127397 + c.charCodeAt(0))) : '🌐');
let regionNames;
export function countryName(cc) {
  try { regionNames ||= new Intl.DisplayNames([navigator.language || 'en'], { type: 'region' }); return regionNames.of(cc.toUpperCase()); } catch (e) { return cc; }
}
export const tz = () => (window.__userTz || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');

export function ago(ts) {
  const d = (Date.now() - new Date(ts.replace(' ', 'T') + 'Z').getTime()) / 1000;
  if (d < 60) return Math.max(0, Math.round(d)) + 's ago';
  if (d < 3600) return Math.round(d / 60) + 'm ago';
  if (d < 86400) return Math.round(d / 3600) + 'h ago';
  return Math.round(d / 86400) + 'd ago';
}
export const localTime = (ts) => new Date(ts.replace(' ', 'T') + 'Z').toLocaleString(undefined, { timeZone: tz(), dateStyle: 'medium', timeStyle: 'short' });
export function useDocTitle(title) {
  useEffect(() => { document.title = title ? `${title} · ${window.SD.name}` : window.SD.name; }, [title]);
}
export const copy = async (text) => { try { await navigator.clipboard.writeText(text); toast('Copied'); } catch (e) { toast('Copy failed', 'err'); } };

// bucket label formatting for charts (labels arrive as local 'YYYY-MM-DD HH:MM:SS')
export function bucketLabel(x, unit, long = false) {
  const d = new Date(x.replace(' ', 'T'));
  if (unit === 'minute') return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  if (unit === 'hour') return long ? d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric' }) : d.toLocaleTimeString(undefined, { hour: 'numeric' });
  if (unit === 'month') return d.toLocaleDateString(undefined, { month: 'short', year: long ? 'numeric' : undefined });
  if (unit === 'year') return String(d.getFullYear());
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: long ? 'numeric' : undefined });
}

// ------------------------------------------------------------------------------------- dashboard scope
// The date menu, in Plausible's order and with its keyboard shortcuts. "yesterday" and "last_month" are not periods of their own: choosing
// them selects `day` or `month` with an earlier date. The older 30d and 6mo windows are no longer offered but still open from a link.
export const PERIODS = [
  ['day', 'Today', 'D'], ['yesterday', 'Yesterday', 'E'], ['realtime', 'Realtime', 'R'], ['24h', 'Last 24 hours', 'H'], ['7d', 'Last 7 days', 'W'], ['28d', 'Last 28 days', 'F'],
  ['91d', 'Last 91 days', 'N'], ['month', 'Month to date', 'M'], ['last_month', 'Last month', 'P'], ['year', 'Year to date', 'Y'], ['12mo', 'Last 12 months', 'L'], ['all', 'All time', 'A'], ['custom', 'Custom range', 'C'],
];
export const DEFAULT_PERIOD = '28d';
export const FILTER_OPS = [['is', 'is'], ['is_not', 'is not'], ['contains', 'contains'], ['contains_not', 'does not contain']];
export const FILTER_DIMS = [
  ['Pages', [['page', 'Page'], ['entry_page', 'Entry page'], ['exit_page', 'Exit page'], ['hostname', 'Hostname']]],
  ['Sources', [['channel', 'Channel'], ['source', 'Source'], ['referrer', 'Referrer URL'], ['utm_medium', 'UTM medium'], ['utm_source', 'UTM source'], ['utm_campaign', 'UTM campaign'], ['utm_content', 'UTM content'], ['utm_term', 'UTM term']]],
  ['Location', [['country', 'Country'], ['region', 'Region'], ['city', 'City']]],
  ['Devices', [['browser', 'Browser'], ['os', 'Operating system'], ['device', 'Device']]],
  ['Behavior', [['goal', 'Goal']]],
];
export const DIM_LABEL = Object.fromEntries(FILTER_DIMS.flatMap(([, l]) => l));
export const dimLabel = (d) => (d.startsWith('prop:') ? d.slice(5) : d === 'segment' ? 'Segment' : DIM_LABEL[d] || d);

export const parseFilters = (q) => { try { const f = JSON.parse(q.filters || '[]'); return Array.isArray(f) ? f : []; } catch (e) { return []; } };
export const setFilters = (list) => setQuery({ filters: list.length ? JSON.stringify(list) : null });
/** Add (or replace) an `is` filter on a dimension — what clicking a row in a report does. */
export function addFilter(dim, value, op = 'is') {
  const list = parseFilters(Object.fromEntries(new URLSearchParams(location.search))).filter((r) => r[1] !== dim);
  setFilters([...list, [op, dim, [value]]]);
}
export const goalFilter = (list) => { const g = list.find((r) => r[1] === 'goal'); return g ? g[2][0] : null; };

export const todayStr = (zone) => new Intl.DateTimeFormat('en-CA', { timeZone: zone || tz() }).format(new Date());
export function shiftDate(dateStr, period, dir) {
  const d = new Date(dateStr + 'T12:00:00Z');
  if (period === 'day') d.setUTCDate(d.getUTCDate() + dir);
  else if (period === 'month') d.setUTCMonth(d.getUTCMonth() + dir, 1);
  else if (period === 'year') d.setUTCFullYear(d.getUTCFullYear() + dir, 0, 1);
  return d.toISOString().slice(0, 10);
}

/** API params derived from the URL: period, date, custom range, comparison, interval and filters. */
const storedPeriodKey = (site) => 'tidemark.period.' + (site ? site.domain : '');
export const storedPeriod = (site) => { try { return localStorage.getItem(storedPeriodKey(site)) || null; } catch (e) { return null; } };
export const rememberPeriod = (site, period) => { try { period ? localStorage.setItem(storedPeriodKey(site), period) : localStorage.removeItem(storedPeriodKey(site)); } catch (e) { /* private mode */ } };

export function useScope(site, extra = {}) {
  const { query } = useLocation();
  // like Plausible, the period last chosen on a site is remembered in this browser
  const remembered = !query.period && !query.date && !query.from && site ? storedPeriod(site) : null;
  const period = query.period || (PERIODS.some((p) => p[0] === remembered) || ['30d', '6mo'].includes(remembered) ? remembered : null) || DEFAULT_PERIOD;
  const params = { period };
  for (const k of ['date', 'from', 'to', 'interval']) if (query[k]) params[k] = query[k];
  if (query.comparison && !['realtime', 'all'].includes(period)) params.comparison = query.comparison;
  if (query.filters) params.filters = query.filters;
  Object.assign(params, extra);
  const filters = parseFilters(query);
  return { params, query, period, filters, goal: goalFilter(filters), key: JSON.stringify(params) };
}

/** Fetch-on-change hook. Returns {data, loading, error, reload}. */
export function useApi(path, params, deps = [], { poll = 0, enabled = true, method = 'GET', body } = {}) {
  const [state, setState] = useState({ data: null, loading: true, error: null });
  const [tick, setTick] = useState(0);
  const key = path + JSON.stringify(params || {}) + JSON.stringify(body || {}) + JSON.stringify(deps) + tick;
  useEffect(() => {
    if (!enabled || !path) return;
    let alive = true;
    setState((s) => ({ ...s, loading: true, error: null }));
    api(path, { params: method === 'GET' ? params : undefined, method, body: method === 'GET' ? undefined : { ...params, ...body } })
      .then((data) => alive && setState({ data, loading: false, error: null }))
      .catch((error) => alive && setState((s) => ({ data: s.data, loading: false, error })));
    return () => { alive = false; };
  }, [key, enabled]);
  useEffect(() => {
    if (!poll || !enabled || !path) return;
    const id = setInterval(() => {
      api(path, { params }).then((data) => setState({ data, loading: false, error: null })).catch(() => {});
    }, poll);
    return () => clearInterval(id);
  }, [key, poll, enabled]);
  return { ...state, reload: () => setTick((t) => t + 1) };
}

// ----------------------------------------------------------------------------------------- theme
/** 'light', 'dark', or 'system' (follow the device). */
export const currentTheme = () => document.documentElement.dataset.theme || 'system';
export function setTheme(theme) {
  const root = document.documentElement;
  try {
    if (theme === 'system') { delete root.dataset.theme; localStorage.removeItem('tidemark.theme'); }
    else { root.dataset.theme = theme; localStorage.setItem('tidemark.theme', theme); }
  } catch (e) { /* private mode */ }
}
export function toggleTheme() {
  const root = document.documentElement;
  const cur = root.dataset.theme || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  const next = cur === 'dark' ? 'light' : 'dark';
  root.dataset.theme = next;
  try { localStorage.setItem('tidemark.theme', next); } catch (e) { /* private mode */ }
}

// -------------------------------------------------------------------------------------------- site branding
// A site's own logo, name and color on its public pages. brandTheme() turns the color into a full readable theme (see brand.js), which
// is applied to the page for as long as it is open and follows the light/dark switch.
const brandSubs = new Set();
let currentBrand = null;
const isDarkNow = () => (document.documentElement.dataset.theme ? document.documentElement.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches);

function paintBrand() {
  const style = document.documentElement.style;
  BRAND_VARS.forEach((v) => style.removeProperty(v));
  const color = currentBrand && /^#[0-9a-f]{6}$/i.test(currentBrand.color || '') ? currentBrand.color : null;
  if (color) for (const [k, v] of Object.entries(brandTheme(color, isDarkNow()).vars)) style.setProperty(k, v);
}

if (typeof document !== 'undefined') {
  new MutationObserver(() => currentBrand && paintBrand()).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => currentBrand && paintBrand());
}

export function setBrand(brand) {
  currentBrand = brand || null;
  paintBrand();
  brandSubs.forEach((fn) => fn(currentBrand));
}

export function useBrand() {
  const [brand, set] = useState(currentBrand);
  useEffect(() => { brandSubs.add(set); return () => brandSubs.delete(set); }, []);
  return brand;
}

/** Lucide icons (https://lucide.dev, ISC) served from one self-hosted sprite: public/assets/icons.svg.
 *  Add an icon: put its Lucide name in scripts/icons.txt and run scripts/build-icons.mjs. */
export const Icon = ({ name, size = 16, class: cls = '', ...rest }) => html`<svg class=${'icon ' + cls} width=${size} height=${size} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" ...${rest}><use href=${`${BASE}/assets/icons.svg?v=${window.SD.icons}#${name}`} /></svg>`;
