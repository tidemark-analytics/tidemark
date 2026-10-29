import { html } from './lib.js';

// A small QR code encoder (byte mode, error correction level M, versions 1-10: enough for an otpauth:// link).

const ECC_PER_BLOCK = [10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
const BLOCKS = [1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
const ALIGN = [[], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];

const rawModules = (v) => {
  let r = (16 * v + 128) * v + 64;
  if (v >= 2) { const n = Math.floor(v / 7) + 2; r -= (25 * n - 10) * n - 55; if (v >= 7) r -= 36; }
  return r;
};

const gfMul = (x, y) => { let z = 0; for (let i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11d); z ^= ((y >>> i) & 1) * x; } return z; };

function rsRemainder(data, degree) {
  const gen = new Array(degree).fill(0); gen[degree - 1] = 1;
  let root = 1;
  for (let i = 0; i < degree; i++) {
    for (let j = 0; j < degree; j++) { gen[j] = gfMul(gen[j], root); if (j + 1 < degree) gen[j] ^= gen[j + 1]; }
    root = gfMul(root, 2);
  }
  const rem = new Array(degree).fill(0);
  for (const b of data) {
    const f = b ^ rem.shift(); rem.push(0);
    gen.forEach((c, i) => { rem[i] ^= gfMul(c, f); });
  }
  return rem;
}

export function qrMatrix(text) {
  const bytes = [...new TextEncoder().encode(text)];
  let ver = 1;
  for (; ver <= 10; ver++) {
    const cap = Math.floor(rawModules(ver) / 8) - ECC_PER_BLOCK[ver - 1] * BLOCKS[ver - 1];
    if (4 + (ver < 10 ? 8 : 16) + bytes.length * 8 <= cap * 8) break;
  }
  if (ver > 10) throw new Error('Text too long for the QR encoder.');
  const ecc = ECC_PER_BLOCK[ver - 1], nb = BLOCKS[ver - 1], total = Math.floor(rawModules(ver) / 8), dataLen = total - ecc * nb;

  const bits = [];
  const put = (v, n) => { for (let i = n - 1; i >= 0; i--) bits.push((v >>> i) & 1); };
  put(4, 4); put(bytes.length, ver < 10 ? 8 : 16); bytes.forEach((b) => put(b, 8));
  put(0, Math.min(4, dataLen * 8 - bits.length));
  while (bits.length % 8) bits.push(0);
  for (let pad = 0xec; bits.length < dataLen * 8; pad ^= 0xec ^ 0x11) put(pad, 8);
  const data = []; for (let i = 0; i < bits.length; i += 8) data.push(parseInt(bits.slice(i, i + 8).join(''), 2));

  const shortBlocks = nb - (total % nb), shortLen = Math.floor(total / nb);
  const blocks = []; const eccs = [];
  for (let i = 0, k = 0; i < nb; i++) {
    const len = shortLen - ecc + (i < shortBlocks ? 0 : 1);
    const d = data.slice(k, k + len); k += len;
    eccs.push(rsRemainder(d, ecc)); blocks.push(d);
  }
  const out = [];
  for (let i = 0; i < blocks[nb - 1].length; i++) blocks.forEach((b) => { if (i < b.length) out.push(b[i]); });
  for (let i = 0; i < ecc; i++) eccs.forEach((e) => out.push(e[i]));

  const size = ver * 4 + 17;
  const mod = Array.from({ length: size }, () => new Array(size).fill(false));
  const fn = Array.from({ length: size }, () => new Array(size).fill(false));
  const set = (x, y, v) => { if (x >= 0 && y >= 0 && x < size && y < size) { mod[y][x] = v; fn[y][x] = true; } };

  for (let i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
  for (const [cx, cy] of [[3, 3], [size - 4, 3], [3, size - 4]]) {
    for (let dy = -4; dy <= 4; dy++) for (let dx = -4; dx <= 4; dx++) { const d = Math.max(Math.abs(dx), Math.abs(dy)); set(cx + dx, cy + dy, d !== 2 && d !== 4); }
  }
  const al = ALIGN[ver - 1];
  al.forEach((ax, i) => al.forEach((ay, j) => {
    if ((i === 0 && j === 0) || (i === 0 && j === al.length - 1) || (i === al.length - 1 && j === 0)) return;
    for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) set(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
  }));
  if (ver >= 7) {
    let rem = ver; for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25);
    const vb = (ver << 12) | rem;
    for (let i = 0; i < 18; i++) { const bit = ((vb >>> i) & 1) === 1, a = size - 11 + (i % 3), b = Math.floor(i / 3); set(a, b, bit); set(b, a, bit); }
  }
  const format = (mask) => {
    const d = mask; let rem = d; for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
    const fb = ((d << 10) | rem) ^ 0x5412, g = (i) => ((fb >>> i) & 1) === 1;
    for (let i = 0; i <= 5; i++) set(8, i, g(i));
    set(8, 7, g(6)); set(8, 8, g(7)); set(7, 8, g(8));
    for (let i = 9; i < 15; i++) set(14 - i, 8, g(i));
    for (let i = 0; i < 8; i++) set(size - 1 - i, 8, g(i));
    for (let i = 8; i < 15; i++) set(8, size - 15 + i, g(i));
    set(8, size - 8, true);
  };
  format(0);

  let n = 0;
  for (let right = size - 1; right >= 1; right -= 2) {
    if (right === 6) right = 5;
    for (let vert = 0; vert < size; vert++) for (let j = 0; j < 2; j++) {
      const x = right - j, y = ((right + 1) & 2) === 0 ? size - 1 - vert : vert;
      if (!fn[y][x] && n < out.length * 8) { mod[y][x] = ((out[n >>> 3] >>> (7 - (n & 7))) & 1) === 1; n++; }
    }
  }

  const masks = [(x, y) => (x + y) % 2 === 0, (x, y) => y % 2 === 0, (x, y) => x % 3 === 0, (x, y) => (x + y) % 3 === 0, (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0,
    (x, y) => ((x * y) % 2) + ((x * y) % 3) === 0, (x, y) => (((x * y) % 2) + ((x * y) % 3)) % 2 === 0, (x, y) => (((x + y) % 2) + ((x * y) % 3)) % 2 === 0];
  const apply = (m) => { for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (!fn[y][x] && masks[m](x, y)) mod[y][x] = !mod[y][x]; };
  const penalty = () => {
    let p = 0, dark = 0;
    for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) {
      if (mod[y][x]) dark++;
      if (x + 1 < size && y + 1 < size && mod[y][x] === mod[y][x + 1] && mod[y][x] === mod[y + 1][x] && mod[y][x] === mod[y + 1][x + 1]) p += 3;
    }
    for (const line of [(a, b) => mod[a][b], (a, b) => mod[b][a]]) for (let a = 0; a < size; a++) {
      let run = 1;
      for (let b = 1; b < size; b++) { if (line(a, b) === line(a, b - 1)) { run++; if (run === 5) p += 3; else if (run > 5) p++; } else run = 1; }
    }
    return p + Math.floor(Math.abs(dark * 20 - size * size * 10) / (size * size)) * 10;
  };
  let best = 0, bestScore = Infinity;
  for (let m = 0; m < 8; m++) { apply(m); const s = penalty(); if (s < bestScore) { best = m; bestScore = s; } apply(m); }
  apply(best); format(best);
  return mod;
}

/** A QR code as an SVG, always dark on white so phone cameras can read it in dark mode too. */
export function QrCode({ text, size = 176 }) {
  const m = qrMatrix(text), n = m.length, q = 3;
  let d = '';
  m.forEach((row, y) => { let x = 0; while (x < n) { if (!row[x]) { x++; continue; } let e = x; while (e < n && row[e]) e++; d += `M${x + q} ${y + q}h${e - x}v1h-${e - x}z`; x = e; } });
  return html`<svg class="qr" viewBox=${`0 0 ${n + q * 2} ${n + q * 2}`} width=${size} height=${size} role="img" aria-label="QR code to scan with your authenticator app" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff" /><path d=${d} fill="#000" /></svg>`;
}
