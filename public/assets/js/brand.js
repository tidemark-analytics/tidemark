// Turns a site's brand color into a complete, readable theme for its public pages.
// The whole page takes the color's hue (backgrounds, borders, text), and the accent is moved the smallest distance needed to meet WCAG 2.1 AA:
// 4.5:1 for text (and the text on buttons), 3:1 for the accent against the page so buttons, focus rings and chart lines stay visible.

const clamp = (n, lo, hi) => Math.min(hi, Math.max(lo, n));

export function hexToRgb(hex) {
  return [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
}

export function rgbToHex([r, g, b]) {
  return '#' + [r, g, b].map((v) => clamp(Math.round(v), 0, 255).toString(16).padStart(2, '0')).join('');
}

/** Hue 0-360, saturation and lightness 0-1. */
export function hexToHsl(hex) {
  const [r, g, b] = hexToRgb(hex).map((v) => v / 255);
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const l = (max + min) / 2;
  const d = max - min;
  if (d === 0) return [0, 0, l];
  const s = d / (1 - Math.abs(2 * l - 1));
  let h;
  if (max === r) h = ((g - b) / d) % 6;
  else if (max === g) h = (b - r) / d + 2;
  else h = (r - g) / d + 4;
  return [(h * 60 + 360) % 360, s, l];
}

export function hslToHex(h, s, l) {
  s = clamp(s, 0, 1);
  l = clamp(l, 0, 1);
  const c = (1 - Math.abs(2 * l - 1)) * s;
  const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
  const m = l - c / 2;
  const [r, g, b] = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x] : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];
  return rgbToHex([(r + m) * 255, (g + m) * 255, (b + m) * 255]);
}

export function luminance(hex) {
  const [r, g, b] = hexToRgb(hex).map((v) => {
    v /= 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrast(a, b) {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}

/**
 * The lightness closest to `start` (stepping 0.5% either way, preferring the `prefer` direction on ties) at which `ok(hex)` holds.
 * Falls back to the end of the range that was tried if nothing qualifies.
 */
function nearestLightness(h, s, start, ok, prefer = 0) {
  for (let step = 0; step <= 200; step++) {
    const d = step * 0.005;
    const order = prefer < 0 ? [-d, d] : [d, -d];
    for (const delta of order) {
      const l = start + delta;
      if (l < 0 || l > 1) continue;
      if (ok(hslToHex(h, s, l))) return l;
    }
  }
  return start;
}

/** Whichever of white and the theme's dark ink reads better on `bg`. */
function textOn(bg, dark) {
  return contrast(bg, '#ffffff') >= contrast(bg, dark) ? '#ffffff' : dark;
}

/**
 * @param {string} hex the color the site picked, like #c2410c
 * @param {boolean} isDark whether the page is in its dark theme
 * @returns {{vars: Record<string,string>, accent: string, changed: boolean}}
 */
export function brandTheme(hex, isDark) {
  const [h, s0, l0] = hexToHsl(hex);
  // neutrals take the hue, with the saturation kept gentle so a vivid brand color still gives a calm page
  const ns = clamp(0.05 + s0 * 0.16, 0.05, 0.2);
  const N = (sat, l) => hslToHex(h, sat, l);

  const n = isDark
    ? { bg: N(ns * 1.2, 0.065), surface: N(ns * 1.1, 0.1), s2: N(ns * 1.1, 0.145), s3: N(ns, 0.185), line: N(ns, 0.175), line2: N(ns * 0.9, 0.26), ink: N(ns * 0.6, 0.94) }
    : { bg: N(ns, 0.96), surface: N(ns * 0.4, 0.995), s2: N(ns, 0.93), s3: N(ns, 0.89), line: N(ns, 0.895), line2: N(ns * 0.9, 0.82), ink: N(Math.min(0.4, ns * 2), 0.08) };
  const toward = isDark ? 1 : -1; // the direction that increases contrast with the page

  const ink2 = hslToHex(h, ns * 0.7, nearestLightness(h, ns * 0.7, isDark ? 0.76 : 0.3, (c) => contrast(c, n.s3) >= 4.5, toward));
  const ink3 = hslToHex(h, ns * 0.7, nearestLightness(h, ns * 0.7, isDark ? 0.62 : 0.4, (c) => contrast(c, n.s2) >= 4.5, toward));

  const darkInk = N(Math.min(0.4, ns * 2), 0.08);
  const pageOk = (c) => contrast(c, n.bg) >= 3 && contrast(c, n.surface) >= 3;
  const fillOk = (c) => pageOk(c) && contrast(c, textOn(c, darkInk)) >= 4.5;
  const sat = clamp(s0, 0, 1);
  const accent = hslToHex(h, sat, nearestLightness(h, sat, l0, fillOk, toward));
  const onAccent = textOn(accent, darkInk);
  // the hover shade keeps the same button text readable
  const hover = hslToHex(h, sat, nearestLightness(h, sat, hexToHsl(accent)[2] + toward * 0.07, (c) => contrast(c, onAccent) >= 4.5, toward));

  const soft = hslToHex(h, Math.min(sat, isDark ? 0.5 : 0.6), isDark ? 0.15 : 0.93);
  const ink = hslToHex(h, sat, nearestLightness(h, sat, hexToHsl(accent)[2], (c) => [n.bg, n.surface, n.s2, soft].every((bg) => contrast(c, bg) >= 4.5), toward));
  const [r, g, b] = hexToRgb(accent);

  return {
    accent,
    changed: accent.toLowerCase() !== hex.toLowerCase(),
    vars: {
      '--bg': n.bg, '--surface': n.surface, '--surface-2': n.s2, '--surface-3': n.s3, '--line': n.line, '--line-2': n.line2,
      '--ink': n.ink, '--ink-2': ink2, '--ink-3': ink3,
      '--accent': accent, '--accent-hover': hover, '--on-accent': onAccent, '--accent-ink': ink, '--accent-soft': soft,
      '--accent-ring': `rgba(${r}, ${g}, ${b}, ${isDark ? 0.4 : 0.3})`,
      '--series-1': accent, '--series-1-wash': `rgba(${r}, ${g}, ${b}, ${isDark ? 0.16 : 0.11})`,
    },
  };
}

export const BRAND_VARS = Object.keys(brandTheme('#0e7c86', false).vars);
