// Reusable UI building blocks.
import { html, useState, useEffect, useLayoutEffect, useRef, copy, toast, Icon, fmt } from './lib.js';

export const Loading = ({ rows = 5 }) => html`<div class="stack sm" aria-busy="true" aria-label="Loading">${Array.from({ length: rows }, (_, i) => html`<div class="skeleton" style=${`height:26px;width:${96 - i * 8}%`}></div>`)}</div>`;
export const Empty = ({ title = 'Nothing here yet', icon = 'chart-column', children }) => html`<div class="empty fade-in"><div class="ico"><${Icon} name=${icon} size=${20} /></div><b>${title}</b>${children && html`<div class="empty-body">${children}</div>`}</div>`;
export const ErrorBox = ({ error }) => (error ? html`<div class="alert" role="alert">${error.message}</div>` : null);
export const Notice = ({ children, icon = 'info' }) => html`<div class="notice"><${Icon} name=${icon} size=${16} /><div>${children}</div></div>`;

export function PageHead({ title, sub, crumb, children }) {
  return html`<header class="page-head"><div class="grow">${crumb && html`<div class="crumb">${crumb}</div>`}<h1>${title}</h1>${sub && html`<div class="sub">${sub}</div>`}</div>${children && html`<div class="row wrap">${children}</div>`}</header>`;
}

export function useClickOutside(ref, fn) {
  useEffect(() => {
    const h = (e) => ref.current && !ref.current.contains(e.target) && fn();
    const k = (e) => e.key === 'Escape' && fn();
    document.addEventListener('mousedown', h);
    document.addEventListener('keydown', k);
    return () => { document.removeEventListener('mousedown', h); document.removeEventListener('keydown', k); };
  }, [fn]);
}

export function Pop({ label, children, align = 'right', btnClass = 'btn', open: openProp, setOpen: setOpenProp, ariaLabel }) {
  const [openState, setOpenState] = useState(false);
  const open = openProp ?? openState;
  const setOpen = setOpenProp ?? setOpenState;
  const ref = useRef();
  useClickOutside(ref, () => open && setOpen(false));
  // keep the popover inside the viewport on narrow screens
  useLayoutEffect(() => {
    const el = open && ref.current && ref.current.querySelector('.pop');
    if (!el) return;
    el.style.translate = '0px';
    const r = el.getBoundingClientRect(), vw = document.documentElement.clientWidth;
    const dx = r.left < 8 ? 8 - r.left : r.right > vw - 8 ? vw - 8 - r.right : 0;
    if (dx) el.style.translate = dx + 'px';
  }, [open]);
  return html`<div class="pop-wrap" ref=${ref}>
    <button class=${btnClass} aria-haspopup="true" aria-expanded=${open} aria-label=${ariaLabel} onClick=${() => setOpen(!open)}>${label}</button>
    ${open && html`<div class=${'pop ' + (align === 'left' ? 'left' : '')}>${typeof children === 'function' ? children(() => setOpen(false)) : children}</div>`}
  </div>`;
}

/** Traps focus, closes on Escape, restores focus to the trigger on close. */
function useDialog(onClose) {
  const ref = useRef();
  useEffect(() => {
    const prev = document.activeElement;
    const root = ref.current;
    const focusables = () => [...root.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')].filter((el) => !el.disabled && el.offsetParent !== null);
    const first = root.querySelector('[autofocus]') || root.querySelector('input, select, textarea') || focusables()[0];
    first && first.focus();
    const key = (e) => {
      if (e.key === 'Escape') { e.stopPropagation(); onClose(); }
      if (e.key === 'Tab') {
        const f = focusables();
        if (!f.length) return;
        const a = f[0], z = f[f.length - 1];
        if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); }
        else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
      }
    };
    document.addEventListener('keydown', key);
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.removeEventListener('keydown', key); document.body.style.overflow = prevOverflow; prev && prev.focus && prev.focus(); };
  }, []);
  return ref;
}

export function Modal({ title, onClose, children, footer, wide }) {
  const ref = useDialog(onClose);
  return html`<div class="overlay" onMouseDown=${(e) => e.target === e.currentTarget && onClose()}>
    <div class=${'modal ' + (wide ? 'wide' : '')} role="dialog" aria-modal="true" aria-label=${title} ref=${ref}>
      <div class="modal-h"><h2>${title}</h2><button class="btn ghost icon" onClick=${onClose} aria-label="Close"><${Icon} name="x" /></button></div>
      <div class="modal-b">${children}</div>
      ${footer && html`<div class="modal-f">${footer}</div>`}
    </div>
  </div>`;
}

export function Drawer({ onClose, children }) {
  const ref = useDialog(onClose);
  return html`<div><div class="overlay" style="padding:0;z-index:54" onMouseDown=${onClose}></div><aside class="drawer" role="dialog" aria-modal="true" ref=${ref}>${children}</aside></div>`;
}

export function Confirm({ title, message, confirmLabel = 'Delete', onConfirm, onClose }) {
  const [busy, setBusy] = useState(false);
  return html`<${Modal} title=${title} onClose=${onClose} footer=${html`
    <button class="btn" onClick=${onClose}>Cancel</button>
    <button class="btn primary" disabled=${busy} onClick=${async () => { setBusy(true); try { await onConfirm(); onClose(); } catch (e) { toast(e.message, 'err'); setBusy(false); } }}>${busy ? 'Working…' : confirmLabel}</button>`}>
    <p style="line-height:1.55;color:var(--ink-2)">${message}</p>
  <//>`;
}

export const Field = ({ label, help, children, class: c }) => html`<label class=${c}>${label}${children}${help && html`<span class="help">${help}</span>`}</label>`;

export function CodeBlock({ text }) {
  return html`<div class="code"><span class="code-t">${text}</span><button class="btn sm" onClick=${() => copy(text)}><${Icon} name="copy" size=${13} /> Copy</button></div>`;
}

/** Tiny area+line chart for a row of numbers. */
export function Sparkline({ data, height = 36 }) {
  if (!data || !data.length) return null;
  const w = 200, max = Math.max(1, ...data);
  const pts = data.map((d, i) => [(i / Math.max(1, data.length - 1)) * w, height - 3 - (d / max) * (height - 8)]);
  const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
  return html`<svg viewBox=${`0 0 ${w} ${height}`} preserveAspectRatio="none" width="100%" height=${height} class="spark" aria-hidden="true">
    <path d=${line + ` L${w} ${height} L0 ${height} Z`} fill="var(--series-1-wash)" /><path d=${line} fill="none" stroke="var(--series-1)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" /></svg>`;
}

export const Card = ({ title, actions, children, pad = true, class: c = '' }) => html`<section class=${'card ' + c}>${(title || actions) && html`<div class="card-h"><h3>${title}</h3><div class="row">${actions}</div></div>`}<div class=${pad ? 'card-b' : 'card-flush'}>${children}</div></section>`;

export function useDebounced(value, ms = 300) {
  const [v, setV] = useState(value);
  useEffect(() => { const id = setTimeout(() => setV(value), ms); return () => clearTimeout(id); }, [value]);
  return v;
}

/** Switch row: a labeled toggle with help text. */
export const Toggle = ({ label, help, checked, onChange, disabled }) => html`<label class="toggle"><input type="checkbox" role="switch" checked=${checked} disabled=${disabled} onChange=${(e) => onChange(e.target.checked)} /><span class="track" aria-hidden="true"></span><span class="grow"><b>${label}</b>${help && html`<span class="help">${help}</span>`}</span></label>`;
