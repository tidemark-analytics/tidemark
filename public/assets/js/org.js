// The organization chosen in the top-bar switcher. Sites, Settings → Organization and Settings → Teams all follow it.
// Every person has a Personal organization; "shared" stands for sites other people's organizations share with them one by one.
import { useState, useEffect } from './lib.js';

const KEY = 'tdm:org';
const subs = new Set();

export const getScope = () => { try { return localStorage.getItem(KEY) || ''; } catch (e) { return ''; } };
export const setScope = (id) => { try { localStorage.setItem(KEY, id); } catch (e) { /* private mode: the choice lasts until reload */ window.__tdmOrg = id; } subs.forEach((f) => f()); };

/** The chosen organization: { id, org, orgs, shared, personal, owner }. Falls back to Personal when the saved choice is gone. */
export function useScope(user) {
  const [, tick] = useState(0);
  useEffect(() => { const f = () => tick((n) => n + 1); subs.add(f); return () => subs.delete(f); }, []);
  const orgs = (user && user.organizations) || [];
  const saved = getScope() || window.__tdmOrg || '';
  const fallback = orgs.find((o) => o.personal) || orgs[0] || null;
  const shared = saved === 'shared' && !!(user && user.shared_sites);
  const org = shared ? null : orgs.find((o) => o.id === saved) || fallback;
  return { id: shared ? 'shared' : org ? org.id : '', org, orgs, shared, personal: !!(org && org.personal), owner: !!(org && org.role === 'owner'), set: setScope };
}
