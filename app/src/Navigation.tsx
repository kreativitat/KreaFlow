/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
import { useEffect, useRef } from 'react';

const paths: Record<string, string> = {
  revenue: 'M4 21V12h3v9M11 21V3h3v18M18 21V8h3v13',
  dashboard: 'M3 10 12 3l9 7M5 9v12h5v-7h4v7h5V9',
  eats: 'M5 3v7m3-7v7M3 6h7m-4 4v11M18 3v18m0-18c-5 3-5 9 0 9',
  kitchen: 'M6 13a4 4 0 0 1-2-7 4 4 0 0 1 7-2 4 4 0 0 1 7 2 4 4 0 0 1 0 7v7H6zM6 16h12',
  forecast: 'M3 3v18h18M6 15l5-5 4 3 6-8m-5 0h5v5',
  stock_forecast: 'M3 7 12 3l9 4v10l-9 4-9-4zM3 7l9 4 9-4m-9 4v10M8 5l9 4',
  produtos: 'M3 7 12 3l9 4v10l-9 4-9-4zM3 7l9 4 9-4m-9 4v10',
  fecho: 'M4 5h16v16H4zM4 10h16M8 3v4m8-4v4M8 14h2m4 0h2m-8 3h2m4 0h2',
  horarios: 'M4 5h16v16H4zM4 10h16M8 3v4m8-4v4M8 14h2m4 0h2',
  barras: 'M4 5v16M8 7h12M8 12h8M8 17h10',
  ponto: 'M12 8v5l3 2M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
  about: 'M12 11v6m0-10v.1M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
  people: 'M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8M2 21v-3a5 5 0 0 1 5-4h4a5 5 0 0 1 5 4v3M17 5a4 4 0 0 1 0 7m1 3a5 5 0 0 1 4 5',
  collapse: 'm14 6-6 6 6 6m6-12-6 6 6 6',
  logout: 'M10 4H4v16h6m4-12 4 4-4 4m-6-4h13'
};
export function UiIcon({ name }: { name: string }) {
  return <svg className="kf-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={paths[name] || paths.dashboard} /></svg>;
}

type Props<T extends string> = {
  tabs: Array<{ key: T; label: string }>;
  activeTab: T;
  onSelect: (key: T) => void;
  open: boolean;
  onClose: () => void;
  collapsed: boolean;
  onCollapse: () => void;
  onLogout: () => void;
  logoutLoading: boolean;
  userName: string;
};

export function Navigation<T extends string>({ tabs, activeTab, onSelect, open, onClose, collapsed, onCollapse, onLogout, logoutLoading, userName }: Props<T>) {
  const drawer = useRef<HTMLDivElement>(null);
  const close = useRef(onClose);
  close.current = onClose;
  useEffect(() => {
    if (!open) return;
    const previous = document.activeElement as HTMLElement | null;
    const sheet = drawer.current;
    sheet?.querySelector<HTMLButtonElement>('button')?.focus();
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') { event.preventDefault(); close.current(); }
      if (event.key !== 'Tab') return;
      const controls = Array.from(sheet?.querySelectorAll<HTMLButtonElement>('button:not(:disabled)') || []);
      const first = controls[0]; const last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    };
    const desktop = window.matchMedia('(min-width: 1200px)');
    const onResize = () => { if (desktop.matches) close.current(); };
    document.addEventListener('keydown', onKey);
    desktop.addEventListener('change', onResize);
    const priorOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      desktop.removeEventListener('change', onResize);
      document.body.style.overflow = priorOverflow;
      previous?.focus();
    };
  }, [open]);

  const items = <>{tabs.map(item => <button key={item.key} type="button"
    className={`nav-drawer-item${activeTab === item.key ? ' active' : ''}`}
    aria-current={activeTab === item.key ? 'page' : undefined}
    title={item.label} onClick={() => onSelect(item.key)}>
    <UiIcon name={item.key} /><span className="kf-nav-label">{item.label}</span>
  </button>)}</>;
  const brand = <img src="/custom/kreaflow/img/kreaflow-logo-transparent.png" className="kf-nav-logo" alt="KreaFlow" />;
  const logout = <button type="button" className="nav-drawer-item" title="Sair" onClick={onLogout} disabled={logoutLoading}><UiIcon name="logout" /><span className="kf-nav-label">{logoutLoading ? 'A sair...' : 'Sair'}</span></button>;
  return <>
    <aside className={`kf-sidebar${collapsed ? ' is-collapsed' : ''}`} aria-label="Navegação KreaFlow">
      <div className="kf-sidebar-brand">{collapsed ? <img src="/custom/kreaflow/img/object_kreaflow.png" width="36" height="36" alt="KreaFlow" /> : brand}</div>
      <nav id="kf-desktop-navigation" className="nav-drawer-list" aria-label="Menus">{items}</nav>
      <div className="nav-drawer-footer">
        <p className="kf-nav-user" title={userName}>{userName}</p>{logout}
        <button type="button" className="nav-drawer-item" onClick={onCollapse} aria-label={collapsed ? 'Expandir menu' : 'Recolher menu'} aria-expanded={!collapsed} title={collapsed ? 'Expandir menu' : 'Recolher menu'}><UiIcon name="collapse" /><span className="kf-nav-label">Recolher menu</span></button>
      </div>
    </aside>
    {open && <section className="nav-drawer-modal" role="dialog" aria-modal="true" aria-label="Navegação">
      <button type="button" className="nav-drawer-backdrop" aria-label="Fechar navegação" onClick={onClose} tabIndex={-1} />
      <div className="nav-drawer-sheet" ref={drawer}>
        <div className="nav-drawer-header">{brand}<button type="button" className="ghost-btn" onClick={onClose}>Fechar</button></div>
        <nav id="kf-navigation" className="nav-drawer-list" aria-label="Menus">{items}</nav>
        <div className="nav-drawer-footer"><p className="kf-nav-user">{userName}</p>{logout}</div>
      </div>
    </section>}
  </>;
}
