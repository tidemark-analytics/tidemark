import { html, useState, useEffect, api, navigate, Link, toast, useLocation, Icon } from '../lib.js';
import { Field } from '../ui.js';
import { Lockup } from '../logo.js';


function AuthShell({ title, sub, children }) {
  return html`<div class="auth-body"><div class="auth-card">
    <div class="brand big"><${Lockup} size=${30} /></div>
    <div><h1>${title}</h1><p class="auth-sub">${sub}</p></div>${children}</div><${LegalLinks} /></div>`;
}

/** Terms / privacy / refund links, shown when the installation configures them (TIDEMARK_TERMS_URL and friends). */
function LegalLinks() {
  const l = window.SD.legal || {};
  const items = [['terms', 'Terms'], ['privacy', 'Privacy notice'], ['refunds', 'Refund policy']].filter(([k]) => l[k]);
  if (!items.length) return null;
  return html`<nav class="legal-links" aria-label="Legal">${items.map(([k, label]) => html`<a href=${l[k]} target="_blank" rel="noopener">${label}</a>`)}</nav>`;
}

export function Login({ setUser }) {
  const [f, setF] = useState({ email: '', password: '', code: '' });
  const [needCode, setNeedCode] = useState(false);
  const [canReset, setCanReset] = useState(false);
  useEffect(() => { api('/config').then((c) => setCanReset(!!c.password_reset)).catch(() => {}); }, []);
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
      ${err && html`<div class="alert" role="alert"><${Icon} name="circle-alert" size=${16} /><div>${err}</div></div>`}
      <${Field} label="Email"><input type="email" required autofocus autocomplete="username" value=${f.email} onInput=${(e) => setF({ ...f, email: e.target.value })} /><//>
      <${Field} label="Password"><input type="password" required autocomplete="current-password" value=${f.password} onInput=${(e) => setF({ ...f, password: e.target.value })} /><//>
      ${needCode && html`<${Field} label="Authentication code" help="Enter the 6-digit code from your authenticator app."><input inputmode="numeric" autocomplete="one-time-code" autofocus value=${f.code} onInput=${(e) => setF({ ...f, code: e.target.value })} /><//>`}
      <button class="btn primary" disabled=${busy}>${busy ? 'Signing in…' : 'Sign in'}</button>
      ${canReset && html`<div class="auth-links"><${Link} to="/forgot-password">Forgot your password?<//></div>`}
      ${window.SD.registration && html`<div class="auth-links">New here? <${Link} to="/register">Create an account<//></div>`}
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
    <form class="stack" onSubmit=${submit}>${err && html`<div class="alert" role="alert"><${Icon} name="circle-alert" size=${16} /><div>${err}</div></div>`}
      <${Field} label="Name"><input required value=${f.name} onInput=${set('name')} /><//>
      <${Field} label="Email"><input type="email" required value=${f.email} onInput=${set('email')} /><//>
      <${Field} label="Password" help="At least 8 characters."><input type="password" required minlength="8" autocomplete="new-password" value=${f.password} onInput=${set('password')} /><//>
      <${Field} label="Confirm password"><input type="password" required autocomplete="new-password" value=${f.password_confirmation} onInput=${set('password_confirmation')} /><//>
      ${(window.SD.legal || {}).terms && html`<p class="help auth-links" style="font-size:12px">By creating an account you agree to the <a href=${window.SD.legal.terms} target="_blank" rel="noopener">Terms of Service</a>${window.SD.legal.privacy ? html` and acknowledge the <a href=${window.SD.legal.privacy} target="_blank" rel="noopener">privacy notice</a>` : ''}.</p>`}
      <button class="btn primary">Create account</button>
      <div class="auth-links">Have an account? <${Link} to="/login">Sign in<//></div>
    </form><//>`;
}

export function ForgotPassword() {
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [err, setErr] = useState('');
  const submit = async (e) => {
    e.preventDefault(); setErr('');
    try { await api('/auth/forgot-password', { method: 'POST', body: { email } }); setSent(true); }
    catch (x) { setErr((x.errors && Object.values(x.errors)[0][0]) || x.message); }
  };
  return html`<${AuthShell} title="Reset your password" sub="We'll email you a link to choose a new one.">
    ${sent ? html`<div class="notice good"><${Icon} name="circle-check" size=${16} /><div>If an account exists for <b>${email}</b>, a reset link is on its way. It expires in an hour.</div></div><${Link} to="/login" class="btn">Back to sign in<//>`
      : html`<form class="stack" onSubmit=${submit}>${err && html`<div class="alert" role="alert"><${Icon} name="circle-alert" size=${16} /><div>${err}</div></div>`}
        <${Field} label="Email"><input type="email" required autofocus autocomplete="username" value=${email} onInput=${(e) => setEmail(e.target.value)} /><//>
        <button class="btn primary">Send reset link</button>
        <div class="auth-links"><${Link} to="/login">Back to sign in<//></div></form>`}<//>`;
}

export function ResetPassword() {
  const { query } = useLocation();
  const [f, setF] = useState({ password: '', password_confirmation: '' });
  const [err, setErr] = useState('');
  const [done, setDone] = useState(false);
  const submit = async (e) => {
    e.preventDefault(); setErr('');
    try { await api('/auth/reset-password', { method: 'POST', body: { ...f, token: query.token, email: query.email } }); setDone(true); }
    catch (x) { setErr((x.errors && Object.values(x.errors)[0][0]) || x.message); }
  };
  if (!query.token || !query.email) return html`<${AuthShell} title="Link problem" sub="This reset link is incomplete."><${Link} to="/forgot-password" class="btn">Request a new link<//><//>`;
  return html`<${AuthShell} title="Choose a new password" sub=${query.email}>
    ${done ? html`<div class="notice good"><${Icon} name="circle-check" size=${16} /><div>Your password was changed.</div></div><${Link} to="/login" class="btn primary">Sign in<//>`
      : html`<form class="stack" onSubmit=${submit}>${err && html`<div class="alert" role="alert"><${Icon} name="circle-alert" size=${16} /><div>${err}</div></div>`}
        <${Field} label="New password" help="At least 8 characters."><input type="password" required minlength="8" autofocus autocomplete="new-password" value=${f.password} onInput=${(e) => setF({ ...f, password: e.target.value })} /><//>
        <${Field} label="Confirm new password"><input type="password" required autocomplete="new-password" value=${f.password_confirmation} onInput=${(e) => setF({ ...f, password_confirmation: e.target.value })} /><//>
        <button class="btn primary">Change password</button></form>`}<//>`;
}
