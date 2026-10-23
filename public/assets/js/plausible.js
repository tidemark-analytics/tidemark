// Reads a Plausible export (Settings → Imports & exports → Export: a zip of CSV files) in the browser and sends it to Tidemark in
// small batches, so a large export never meets a server upload limit. Nothing is held in memory but the file being read.
import { api } from './lib.js';

const u16 = (v, o) => v.getUint16(o, true);
const u32 = (v, o) => v.getUint32(o, true);

/** Lists the files in a zip by reading its central directory. Returns { name, method, size, offset } for each. */
export async function zipEntries(file) {
  const tailSize = Math.min(file.size, 65557);
  const tail = new DataView(await file.slice(file.size - tailSize).arrayBuffer());
  let eocd = -1;
  for (let i = tail.byteLength - 22; i >= 0; i--) if (u32(tail, i) === 0x06054b50) { eocd = i; break; }
  if (eocd < 0) throw new Error('This does not look like a zip file. Use the .zip that Plausible prepared for download.');
  let count = u16(tail, eocd + 10);
  let cdSize = u32(tail, eocd + 12);
  let cdOffset = u32(tail, eocd + 16);
  if (cdOffset === 0xffffffff || count === 0xffff) { // zip64
    const loc = eocd - 20;
    if (loc < 0 || u32(tail, loc) !== 0x07064b50) throw new Error('This zip file is damaged.');
    const rec = Number(tail.getBigUint64(loc + 8, true));
    const z = new DataView(await file.slice(rec, rec + 56).arrayBuffer());
    count = Number(z.getBigUint64(32, true));
    cdSize = Number(z.getBigUint64(40, true));
    cdOffset = Number(z.getBigUint64(48, true));
  }
  const cd = new DataView(await file.slice(cdOffset, cdOffset + cdSize).arrayBuffer());
  const names = new TextDecoder();
  const entries = [];
  let pos = 0;
  for (let i = 0; i < count && pos + 46 <= cd.byteLength; i++) {
    if (u32(cd, pos) !== 0x02014b50) throw new Error('This zip file is damaged.');
    const e = { method: u16(cd, pos + 10), size: u32(cd, pos + 20), offset: u32(cd, pos + 42) };
    const nameLen = u16(cd, pos + 28); const extraLen = u16(cd, pos + 30); const commentLen = u16(cd, pos + 32);
    e.name = names.decode(new Uint8Array(cd.buffer, cd.byteOffset + pos + 46, nameLen));
    let x = pos + 46 + nameLen; const xEnd = x + extraLen;
    while (x + 4 <= xEnd) { // zip64 values replace the ones that did not fit in four bytes
      const id = u16(cd, x); const len = u16(cd, x + 2);
      if (id === 0x0001) {
        let p = x + 4;
        if (u32(cd, pos + 24) === 0xffffffff) { p += 8; }
        if (u32(cd, pos + 20) === 0xffffffff) { e.size = Number(cd.getBigUint64(p, true)); p += 8; }
        if (u32(cd, pos + 42) === 0xffffffff) { e.offset = Number(cd.getBigUint64(p, true)); }
      }
      x += 4 + len;
    }
    entries.push(e);
    pos += 46 + nameLen + extraLen + commentLen;
  }
  return entries;
}

/** The decoded text of one zip entry as a stream. */
async function entryStream(file, e) {
  const head = new DataView(await file.slice(e.offset, e.offset + 30).arrayBuffer());
  if (u32(head, 0) !== 0x04034b50) throw new Error('This zip file is damaged.');
  const start = e.offset + 30 + u16(head, 26) + u16(head, 28);
  let s = file.slice(start, start + e.size).stream();
  if (e.method === 8) s = s.pipeThrough(new DecompressionStream('deflate-raw'));
  else if (e.method !== 0) throw new Error('This zip uses a compression method Tidemark cannot read.');
  return s.pipeThrough(new TextDecoderStream());
}

/** Rows of a CSV stream as arrays of strings (handles quoted fields, doubled quotes and line breaks inside quotes). */
async function* csvRows(stream) {
  const reader = stream.getReader();
  let field = ''; let row = []; let quoted = false; let afterQuote = false;
  for (;;) {
    const { value, done } = await reader.read();
    if (done) break;
    for (let i = 0; i < value.length; i++) {
      const c = value[i];
      if (quoted) {
        if (c === '"') { quoted = false; afterQuote = true; } else field += c;
      } else if (c === '"') {
        if (afterQuote) { field += '"'; quoted = true; afterQuote = false; } else if (field === '') quoted = true; else field += c;
      } else if (c === ',') { row.push(field); field = ''; afterQuote = false; }
      else if (c === '\n' || c === '\r') {
        if (c === '\r' && value[i + 1] === '\n') i++;
        afterQuote = false;
        if (field !== '' || row.length) { row.push(field); yield row; row = []; field = ''; }
      } else { field += c; afterQuote = false; }
    }
  }
  if (field !== '' || row.length) { row.push(field); yield row; }
}

const METRICS = ['visitors', 'visits', 'pageviews', 'bounces', 'visit_duration'];
const fileFor = (entries, name) => entries.find((e) => new RegExp(`(^|/)${name}(_\\d{8}_\\d{8})?\\.csv$`).test(e.name));

/**
 * Sends the export to the site. `progress` gets { phase, done, total, sent, skipped } as it goes; the result says how many days were imported.
 * Throws with a readable message if the file is not a Plausible export.
 */
export async function importPlausible(file, domain, progress) {
  const entries = await zipEntries(file);
  const visitorsFile = fileFor(entries, 'imported_visitors');
  if (!visitorsFile) throw new Error('This zip has no imported_visitors.csv, so it is not a Plausible export.');

  const base = `/sites/${domain}/import/plausible`;
  const { from, until } = await api(`${base}/start`, { method: 'POST' });
  const state = { sent: 0, skipped: 0, days: 0 };
  const buf = { daily: [], rows: [], sources: [] };
  const size = () => buf.daily.length + buf.rows.length + buf.sources.length;
  const inRange = (d) => (!from || d >= from) && (!until || d <= until);
  const flush = async () => {
    if (!size()) return;
    const r = await api(base, { method: 'POST', body: buf });
    state.sent += r.rows + r.days; state.skipped += r.skipped; state.days += r.days;
    buf.daily = []; buf.rows = []; buf.sources = [];
  };
  const push = async (kind, row) => { buf[kind].push(row); if (size() >= 4000) await flush(); };

  const total = entries.reduce((n, e) => n + e.size, 0);
  let read = 0;
  const wanted = [
    ['imported_visitors', null], ['imported_sources', 'Visitors, sources'], ['imported_pages', 'Pages'], ['imported_entry_pages', 'Entry pages'], ['imported_exit_pages', 'Exit pages'],
    ['imported_custom_events', 'Custom events'], ['imported_locations', 'Locations'], ['imported_devices', 'Devices'], ['imported_browsers', 'Browsers'], ['imported_operating_systems', 'Operating systems'],
  ];

  for (const [name, label] of wanted) {
    const e = fileFor(entries, name);
    if (!e) continue;
    progress({ phase: label || 'Visitors', done: read, total, ...state });
    let idx = null; let n = 0; let lastDate = null; let agg = new Map();
    const col = (r, c) => r[idx[c]] ?? '';
    const num = (r, c) => Number(col(r, c)) || 0;
    const metrics = (r) => Object.fromEntries(METRICS.map((m) => [m, num(r, m)]));
    const add = (dim, name, m) => {
      const k = `${dim}\u0000${name}`;
      const cur = agg.get(k) || { key: { dim, name }, ...Object.fromEntries(METRICS.map((x) => [x, 0])) };
      METRICS.forEach((x) => { cur[x] += m[x] || 0; });
      agg.set(k, cur);
    };
    // totals per day and name are kept until the date changes, then sent
    const flushPacked = async () => { for (const cur of agg.values()) { const { key, ...m } = cur; await push('rows', { date: lastDate, dim: key.dim, name: key.name, ...m }); } agg = new Map(); };

    for await (const r of csvRows(await entryStream(file, e))) {
      if (!idx) { idx = Object.fromEntries(r.map((h, i) => [h, i])); if (!('date' in idx)) throw new Error(`${e.name} has no date column.`); continue; }
      const date = col(r, 'date');
      if (++n % 20000 === 0) progress({ phase: label || 'Visitors', done: read + Math.min(e.size, n * 80), total, ...state });
      if (!inRange(date)) { state.skipped++; continue; }
      if (name === 'imported_visitors') { await push('daily', { date, ...metrics(r) }); continue; }
      if (name === 'imported_sources') { await push('sources', { date, source: col(r, 'source'), referrer: col(r, 'referrer'), utm_source: col(r, 'utm_source'), utm_medium: col(r, 'utm_medium'), utm_campaign: col(r, 'utm_campaign'), utm_content: col(r, 'utm_content'), utm_term: col(r, 'utm_term'), ...metrics(r) }); continue; }
      if (name === 'imported_pages') { await push('rows', { date, dim: 'page', name: col(r, 'page'), visitors: num(r, 'visitors'), visits: num(r, 'visits'), pageviews: num(r, 'pageviews') }); continue; }
      if (name === 'imported_entry_pages') { await push('rows', { date, dim: 'entry_page', name: col(r, 'entry_page'), visitors: num(r, 'visitors'), visits: num(r, 'entrances'), pageviews: num(r, 'pageviews'), bounces: num(r, 'bounces'), visit_duration: num(r, 'visit_duration') }); continue; }
      if (name === 'imported_exit_pages') { await push('rows', { date, dim: 'exit_page', name: col(r, 'exit_page'), visitors: num(r, 'visitors'), visits: num(r, 'exits'), pageviews: num(r, 'pageviews'), bounces: num(r, 'bounces'), visit_duration: num(r, 'visit_duration') }); continue; }
      // one row per day and name instead of one per city, browser version, operating system version or link
      if (lastDate !== null && date !== lastDate) await flushPacked();
      if (name === 'imported_custom_events') add('event', col(r, 'name'), { visitors: num(r, 'visitors'), visits: num(r, 'events') });
      else {
        const m = metrics(r);
        if (name === 'imported_locations') { const cc = col(r, 'country'); if (cc && cc !== 'ZZ' && cc.length === 2) { add('country', cc, m); if (col(r, 'region')) add('region', col(r, 'region'), m); } }
        else if (name === 'imported_devices') add('device', col(r, 'device'), m);
        else if (name === 'imported_browsers') add('browser', col(r, 'browser'), m);
        else if (name === 'imported_operating_systems') add('os', col(r, 'operating_system'), m);
      }
      lastDate = date;
    }
    if (agg.size) await flushPacked();
    read += e.size;
  }
  await flush();
  progress({ phase: 'Finishing', done: total, total, ...state });
  const summary = await api(`${base}/finish`, { method: 'POST' });

  return { ...summary, skipped: state.skipped, customProps: !!fileFor(entries, 'imported_custom_props') };
}

export const removeImported = (domain) => api(`/sites/${domain}/import/plausible`, { method: 'DELETE' });
