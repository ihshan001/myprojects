import React from 'react';
import { COLORS } from '../data/clinic';

/* ------------------------------- Icons ------------------------------- */

type IconProps = { size?: number; color?: string; stroke?: number };

const S = (size = 20, color = 'currentColor', stroke = 1.9) => ({
  width: size, height: size, viewBox: '0 0 24 24', fill: 'none',
  stroke: color, strokeWidth: stroke, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const,
});

export const Icon = {
  back: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)} style={{ display: 'block' }}><path d="M15 18l-6-6 6-6" /></svg>
  ),
  chevron: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M9 18l6-6-6-6" /></svg>
  ),
  bell: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.7 21a2 2 0 01-3.4 0" /></svg>
  ),
  plus: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color, 2.4)}><path d="M12 5v14M5 12h14" /></svg>
  ),
  close: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color, 2.2)}><path d="M18 6L6 18M6 6l12 12" /></svg>
  ),
  home: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M3 10.5L12 3l9 7.5" /><path d="M5 9.5V21h14V9.5" /><path d="M9.5 21v-6h5v6" /></svg>
  ),
  map: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M9 3L3 6v15l6-3 6 3 6-3V3l-6 3-6-3z" /><path d="M9 3v15M15 6v15" /></svg>
  ),
  gear: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><circle cx="12" cy="12" r="3.2" /><path d="M19.4 15a1.7 1.7 0 00.3 1.9l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.9-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.9.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.9 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.9l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.9.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.9-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.9V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z" /></svg>
  ),
  user: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><circle cx="12" cy="8" r="3.6" /><path d="M4.5 21a7.5 7.5 0 0115 0" /></svg>
  ),
  cart: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><circle cx="9.5" cy="19.5" r="1.4" /><circle cx="18" cy="19.5" r="1.4" /><path d="M2.5 3h2.2l2.4 12h11.6l2-8H6" /></svg>
  ),
  search: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><circle cx="11" cy="11" r="6.5" /><path d="M16 16l4.5 4.5" /></svg>
  ),
  check: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color, 2.6)}><path d="M4.5 12.5l5 5L20 7" /></svg>
  ),
  checkCircle: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color, 1.8)}><circle cx="12" cy="12" r="9.2" /><path d="M8 12.4l2.8 2.8L16.4 9.6" /></svg>
  ),
  xCircle: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color, 1.8)}><circle cx="12" cy="12" r="9.2" /><path d="M9 9l6 6M15 9l-6 6" /></svg>
  ),
  clock: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><circle cx="12" cy="12" r="9" /><path d="M12 7.5V12l3 1.8" /></svg>
  ),
  pin: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M12 21s7-5.4 7-11a7 7 0 10-14 0c0 5.6 7 11 7 11z" /><circle cx="12" cy="10" r="2.6" /></svg>
  ),
  pill: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><rect x="2.6" y="8.6" width="18.8" height="6.8" rx="3.4" transform="rotate(-45 12 12)" /><path d="M8.4 8.4l7.2 7.2" /></svg>
  ),
  flask: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M9 3h6M10 3v6.2L5.4 18a2 2 0 001.7 3h9.8a2 2 0 001.7-3L14 9.2V3" /><path d="M7.5 14h9" /></svg>
  ),
  scan: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M3 8V5.5A2.5 2.5 0 015.5 3H8M16 3h2.5A2.5 2.5 0 0121 5.5V8M21 16v2.5A2.5 2.5 0 0118.5 21H16M8 21H5.5A2.5 2.5 0 013 18.5V16" /><path d="M7 12h10" /></svg>
  ),
  stethoscope: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M5 3v5a4 4 0 008 0V3" /><path d="M5 3H3.5M13 3h1.5" /><path d="M9 12v2.5a5 5 0 0010 0V13" /><circle cx="19" cy="11" r="2" /></svg>
  ),
  calendar: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><rect x="3.2" y="5" width="17.6" height="16" rx="2.6" /><path d="M8 3v4M16 3v4M3.2 10.5h17.6" /></svg>
  ),
  trash: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M4 7h16M9 7V4.6h6V7M6.5 7l1 13h9l1-13" /></svg>
  ),
  logout: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M15 4h3.5A1.5 1.5 0 0120 5.5v13a1.5 1.5 0 01-1.5 1.5H15" /><path d="M10 8l-4 4 4 4M6 12h9" /></svg>
  ),
  doc: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M6 3h7.5L19 8.5V21H6z" /><path d="M13 3v6h6M9.5 13h6M9.5 17h4" /></svg>
  ),
  clipboard: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><rect x="5" y="4.5" width="14" height="16.5" rx="2.2" /><path d="M9 4.5V3.4A1.4 1.4 0 0110.4 2h3.2A1.4 1.4 0 0115 3.4v1.1" /><path d="M8.5 11h7M8.5 15h4.5" /></svg>
  ),
  envelope: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><rect x="2.8" y="5" width="18.4" height="14" rx="2.4" /><path d="M3.6 6.4l8.4 6.2 8.4-6.2" /></svg>
  ),
  refresh: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M20 11a8 8 0 10-2.6 6.2" /><path d="M20 5v6h-6" /></svg>
  ),
  arrowRight: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color, 2.1)}><path d="M5 12h13M12.5 5.5L19 12l-6.5 6.5" /></svg>
  ),
  undo: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M9 14L4 9l5-5" /><path d="M4 9h8.5a6.5 6.5 0 010 13H9" /></svg>
  ),
  walk: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><circle cx="13" cy="4" r="1.9" /><path d="M11 8l-2.5 5.5L5 16M11 8l3.5 1.5 2 5M13 20l-1-6-3-2" /></svg>
  ),
  tap: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><path d="M9 11V3.8a1.8 1.8 0 013.6 0V11" /><path d="M12.6 11.5V9.6a1.7 1.7 0 013.4 0v2.6a1.7 1.7 0 013.4 0V16a5.5 5.5 0 01-5.5 5.5h-1.6a5 5 0 01-3.7-1.6l-3.3-3.7a1.7 1.7 0 012.5-2.3L9 15.4" /></svg>
  ),
  plus2: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color, 2.4)}><path d="M12 5v14M5 12h14" /></svg>
  ),
  info: (p: IconProps = {}) => (
    <svg {...S(p.size, p.color)}><circle cx="12" cy="12" r="9" /><path d="M12 11v5.5M12 7.8v.6" /></svg>
  ),
};

/* --------------------------- Status bar --------------------------- */

export function StatusBar({ light = false }: { light?: boolean }) {
  const time = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }).replace(' ', '');
  return (
    <div className={`statusbar ${light ? 'light' : ''}`}>
      <span>{time}</span>
      <div className="statusbar-icons">
        <div className="bar" style={{ height: 5 }} />
        <div className="bar" style={{ height: 8 }} />
        <div className="bar" style={{ height: 11 }} />
        <svg width="16" height="12" viewBox="0 0 16 12" fill="none" style={{ marginLeft: 3 }}>
          <path d="M8 10.6a1 1 0 100-2 1 1 0 000 2z" fill="currentColor" />
          <path d="M4.6 8.1a4.8 4.8 0 016.8 0" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
          <path d="M1.9 5.4a8.7 8.7 0 0112.2 0" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
        </svg>
        <svg width="25" height="12" viewBox="0 0 25 12" fill="none" style={{ marginLeft: 3 }}>
          <rect x="0.7" y="0.7" width="20" height="10.6" rx="3" stroke="currentColor" strokeWidth="1.1" opacity="0.45" />
          <rect x="2.5" y="2.5" width="15" height="7" rx="1.8" fill="currentColor" />
          <path d="M22.6 4.1v3.8a2.2 2.2 0 000-3.8z" fill="currentColor" opacity="0.45" />
        </svg>
      </div>
    </div>
  );
}

/* --------------------------- Buttons --------------------------- */

export function PrimaryButton({
  children, onClick, disabled, variant = 'solid', style,
}: {
  children: React.ReactNode; onClick?: () => void; disabled?: boolean;
  variant?: 'solid' | 'ghost' | 'danger'; style?: React.CSSProperties;
}) {
  const cls = variant === 'solid' ? 'primary-btn' : variant === 'ghost' ? 'primary-btn ghost' : 'primary-btn danger';
  return (
    <button className={cls} onClick={onClick} disabled={disabled} style={style}>
      {children}
    </button>
  );
}

/* --------------------------- Fields --------------------------- */

export function Field({
  value, onChange, placeholder, type = 'text', disabled, icon, maxLength, inputMode,
}: {
  value: string; onChange: (v: string) => void; placeholder: string;
  type?: string; disabled?: boolean; icon?: React.ReactNode; maxLength?: number;
  inputMode?: 'text' | 'numeric' | 'decimal';
}) {
  return (
    <div style={{ position: 'relative', width: '100%' }}>
      {icon && (
        <span style={{ position: 'absolute', left: 15, top: '50%', transform: 'translateY(-50%)', color: '#94a3b8', display: 'flex' }}>
          {icon}
        </span>
      )}
      <input
        className="field"
        style={icon ? { paddingLeft: 42 } : undefined}
        type={type}
        inputMode={inputMode}
        maxLength={maxLength}
        placeholder={placeholder}
        value={value}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
      />
    </div>
  );
}

export function SearchField({ value, onChange, placeholder }: { value: string; onChange: (v: string) => void; placeholder: string }) {
  return (
    <div style={{ position: 'relative', width: '100%' }}>
      <span style={{ position: 'absolute', left: 16, top: '50%', transform: 'translateY(-50%)', color: '#a5a5a5', display: 'flex' }}>
        {Icon.search({ size: 17 })}
      </span>
      <input
        className="field"
        style={{ paddingLeft: 43, borderRadius: 100 }}
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </div>
  );
}

/* --------------------------- Navigation --------------------------- */

export function BackHeader({
  title, onBack, right, dark = false,
}: { title?: string; onBack?: () => void; right?: React.ReactNode; dark?: boolean }) {
  return (
    <div className="nav-header" style={dark ? { background: 'transparent' } : undefined}>
      {onBack && (
        <button className="back-btn" onClick={onBack} style={dark ? { background: 'rgba(255,255,255,.16)', color: '#fff' } : undefined}>
          {Icon.back({ size: 18 })}
        </button>
      )}
      <div className="h3 grow" style={{ textAlign: 'center', marginRight: onBack ? 38 : 0 }}>{title}</div>
      {right}
    </div>
  );
}

/* --------------------------- Bits --------------------------- */

export function Badge({ children, tone = 'gray' }: { children: React.ReactNode; tone?: 'gray' | 'green' | 'orange' | 'red' | 'blue' | 'teal' | 'pink' }) {
  return <span className={`badge ${tone === 'gray' ? '' : tone}`}>{children}</span>;
}

export function Ring({ value, size = 66, children }: { value: number; size?: number; children?: React.ReactNode }) {
  const r = (size - 8) / 2;
  const c = 2 * Math.PI * r;
  return (
    <div className="ring" style={{ width: size, height: size, background: 'transparent' }}>
      <svg width={size} height={size} style={{ position: 'absolute' }}>
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="rgba(71,148,132,.16)" strokeWidth={7} />
        <circle
          cx={size / 2} cy={size / 2} r={r} fill="none" stroke={COLORS.themeTeal} strokeWidth={7}
          strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c - (c * Math.min(value, 100)) / 100}
          transform={`rotate(-90 ${size / 2} ${size / 2})`} style={{ transition: 'stroke-dashoffset .5s ease' }}
        />
      </svg>
      <span>{children}</span>
    </div>
  );
}

export function InfoRow({ icon, label, value }: { icon?: React.ReactNode; label: string; value: string }) {
  return (
    <div className="row gap-6" style={{ fontSize: 12, color: COLORS.text }}>
      {icon && <span style={{ display: 'flex', color: '#94a3b8' }}>{icon}</span>}
      <span>{label}</span>
      <span style={{ color: COLORS.textDark, fontWeight: 600 }}>{value}</span>
    </div>
  );
}

export function SectionHeading({ children, action }: { children: React.ReactNode; action?: React.ReactNode }) {
  return (
    <div className="between" style={{ marginBottom: 12 }}>
      <div className="h2">{children}</div>
      {action}
    </div>
  );
}

export function EmptyState({ icon, title, text }: { icon?: React.ReactNode; title: string; text?: string }) {
  return (
    <div className="stack" style={{ alignItems: 'center', textAlign: 'center', padding: '34px 26px', gap: 10 }}>
      <div style={{
        width: 54, height: 54, borderRadius: 18, background: '#f1f5f9',
        display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#94a3b8',
      }}>{icon ?? Icon.info({ size: 22 })}</div>
      <div className="h3">{title}</div>
      {text && <div className="small muted" style={{ lineHeight: 1.5 }}>{text}</div>}
    </div>
  );
}

export function QRCode({ content, size = 120 }: { content: string; size?: number }) {
  // Deterministic pseudo-QR: encodes the content into a 25x25 matrix with finder patterns.
  const modules = 25;
  let h = 2166136261;
  for (let i = 0; i < content.length; i++) { h ^= content.charCodeAt(i); h = Math.imul(h, 16777619); }
  const bits: boolean[] = [];
  let state = h >>> 0;
  for (let i = 0; i < modules * modules; i++) {
    state ^= state << 13; state ^= state >>> 17; state ^= state << 5; state >>>= 0;
    bits.push((state & 0xff) > 118);
  }
  const isFinder = (x: number, y: number) => {
    const inBox = (ox: number, oy: number) => x >= ox && x < ox + 7 && y >= oy && y < oy + 7;
    return inBox(0, 0) || inBox(modules - 7, 0) || inBox(0, modules - 7);
  };
  const finderOn = (x: number, y: number) => {
    const lx = x < 7 ? x : x - (modules - 7);
    const ly = y < 7 ? y : y - (modules - 7);
    if (x < 7 && y >= modules - 7) return false;
    const ring = Math.max(Math.abs(lx - 3), Math.abs(ly - 3));
    return ring === 3 || ring <= 1;
  };
  const cell = size / modules;
  const rects: React.ReactNode[] = [];
  for (let y = 0; y < modules; y++) {
    for (let x = 0; x < modules; x++) {
      const on = isFinder(x, y) ? finderOn(x, y) : bits[y * modules + x];
      if (on) rects.push(<rect key={`${x}-${y}`} x={x * cell} y={y * cell} width={cell} height={cell} fill="#0b0f14" />);
    }
  }
  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} shapeRendering="crispEdges" style={{ display: 'block' }}>
      <rect width={size} height={size} fill="#fff" />
      {rects}
    </svg>
  );
}
