import React, { useState } from 'react';
import { useClinic } from '../data/store';
import { Icon, StatusBar } from './ui';

/* ------------------------- Floating quick nav ------------------------- */

export function FloatingNav({ light = false }: { light?: boolean }) {
  const [open, setOpen] = useState(false);
  const { push } = useClinic();
  const items = [
    { icon: Icon.home, label: 'Home', run: () => push('services') },
    { icon: Icon.map, label: 'Map', run: () => push('map') },
    { icon: Icon.gear, label: 'Settings', run: () => push('profile') },
  ];
  return (
    <div className="fab-wrap">
      {open && items.map((item, i) => (
        <button
          key={item.label}
          className="fab-item"
          style={{ animationDelay: `${i * 0.03}s` }}
          title={item.label}
          onClick={() => { setOpen(false); item.run(); }}
        >
          {item.icon({ size: 19 })}
        </button>
      ))}
      <button
        className="fab"
        onClick={() => setOpen((o) => !o)}
        style={light ? { background: '#fff', color: '#169A8F' } : undefined}
        aria-label="Quick navigation"
      >
        {open ? Icon.close({ size: 24 }) : Icon.plus({ size: 26 })}
      </button>
    </div>
  );
}

/* ------------------------------- Frame ------------------------------- */

export function Screen({
  children, statusLight = false, homeLight = false, fab,
}: {
  children: React.ReactNode;
  statusLight?: boolean;
  homeLight?: boolean;
  fab?: React.ReactNode;
}) {
  return (
    <>
      <div className="device-notch" />
      <StatusBar light={statusLight} />
      <div className="screen-scroll">{children}</div>
      {fab}
      <div className={`home-indicator ${homeLight ? 'light' : ''}`}><span /></div>
    </>
  );
}
