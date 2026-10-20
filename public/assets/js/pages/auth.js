import { html, useState, api, navigate, Link, toast } from '../lib.js';
import { Field } from '../ui.js';
import { Lockup } from '../logo.js';


function AuthShell({ title, sub, children }) {
  return html`<div class="auth-body"><div class="auth-card">
    <div class="brand big"><${Lockup} size=${34} /></div>
    <div><h1>${title}</h1><p class="muted" style="margin:4px 0 0">${sub}</p></div>${children}</div></div>`;
}

export function Login({ setUser }) {
  const [f, setF] = useState({ email: '', password: '', code: '' });
  const [needCode, setNeedCode] = useState(false);
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const submit = async (e) => {
    e.preventDefault(); setBusy(true); setErr('');
    try {
      const r = await api('/auth/login', { method: 'POST', body: f });
      if (r._status === 202) { setNeedCode(true); setBusy(false); return; }
      setUser(r.user); navigate('/sites');
    } catch (x) { setErr((x.errors && Object.values(x.errors)[0][0]) || x.message); setBusy(false); }
  };
  return html`<${AuthShell} title="Welcome back" sub="Sign in to your analytics.">
    <form class="stack" onSubmit=${submit}>
      ${err && html`<div class="alert">${err}</div>`}
      <${Field} label="Email"><input type="email" required autofocus autocomplete="username" value=${f.email} onInput=${(e) => setF({ ...f, email: e.target.value })} /><//>
      <${Field} label="Password"><input type="password" required autocomplete="current-password" value=${f.password} onInput=${(e) => setF({ ...f, password: e.target.value })} /><//>
      ${needCode && html`<${Field} label="Authentication code" help="Enter the 6-digit code from your authenticator app."><input inputmode="numeric" autocomplete="one-time-code" autofocus value=${f.code} onInput=${(e) => setF({ ...f, code: e.target.value })} /><//>`}
      <button class="btn primary" style="height:38px" disabled=${busy}>${busy ? 'Signing in…' : 'Sign in'}</button>
      ${window.SD.registration && html`<div class="muted" style="text-align:center">New here? <${Link} to="/register" style="color:var(--accent-ink);font-weight:600">Create an account<//></div>`}
    </form><//>`;
}

export function Register({ setUser }) {
  const [f, setF] = useState({ name: '', email: '', password: '', password_confirmation: '' });
  const [err, setErr] = useState('');
  if (!window.SD.registration) return html`<${AuthShell} title="Registration is closed" sub="Ask an administrator for an account."><${Link} to="/login" class="btn">Back to sign in<//><//>`;
  const submit = async (e) => {
    e.preventDefault(); setErr('');
    try { const r = await api('/auth/register', { method: 'POST', body: f }); setUser(r.user); navigate('/sites'); toast('Welcome!'); }
    catch (x) { setErr((x.errors && Object.values(x.errors)[0][0]) || x.message); }
  };
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  return html`<${AuthShell} title="Create your account" sub="Privacy-first analytics in a minute.">
    <form class="stack" onSubmit=${submit}>${err && html`<div class="alert">${err}</div>`}
      <${Field} label="Name"><input required value=${f.name} onInput=${set('name')} /><//>
      <${Field} label="Email"><input type="email" required value=${f.email} onInput=${set('email')} /><//>
      <${Field} label="Password" help="At least 8 characters."><input type="password" required minlength="8" autocomplete="new-password" value=${f.password} onInput=${set('password')} /><//>
      <${Field} label="Confirm password"><input type="password" required autocomplete="new-password" value=${f.password_confirmation} onInput=${set('password_confirmation')} /><//>
      <button class="btn primary" style="height:38px">Create account</button>
      <div class="muted" style="text-align:center">Have an account? <${Link} to="/login" style="color:var(--accent-ink);font-weight:600">Sign in<//></div>
    </form><//>`;
}
