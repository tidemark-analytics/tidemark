// Shell: Remembered state: recently opened sites and the sidebar collapse state.
import { useState, useEffect } from './lib.js';

const RECENT_KEY = 'tidemark.recent';
const readRecents = () => { try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]').filter((d) => typeof d === 'string'); } catch (e) { return []; } };
export function useRecents(domain) {
  const [recents, setRecents] = useState(readRecents);
  useEffect(() => {
    if (!domain) return;
    const next = [domain, ...readRecents().filter((d) => d !== domain)].slice(0, 6);
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(next)); } catch (e) { /* private mode */ }
    setRecents(next);
  }, [domain]);
  return recents;
}

const SIDE_KEY = 'tidemark.side';
export function useSidebarState(path) {
  const [collapsed, setCollapsed] = useState(() => { try { return localStorage.getItem(SIDE_KEY) === 'collapsed'; } catch (e) { return false; } });
  const [open, setOpen] = useState(false);
  useEffect(() => setOpen(false), [path]);
  useEffect(() => {
    if (!open) return;
    const k = (e) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('keydown', k);
    return () => document.removeEventListener('keydown', k);
  }, [open]);
  const toggleCollapsed = () => setCollapsed((c) => { const n = !c; try { localStorage.setItem(SIDE_KEY, n ? 'collapsed' : 'expanded'); } catch (e) { /* private mode */ } return n; });
  return { collapsed, open, setOpen, toggleCollapsed };
}
