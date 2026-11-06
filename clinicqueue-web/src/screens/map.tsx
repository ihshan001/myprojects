import React, { useMemo, useState } from 'react';
import { useClinic } from '../data/store';
import { groundRooms, upperRooms, roomByName, ClinicRoom, COLORS, roomsForFloor } from '../data/clinic';
import { Screen } from '../components/shell';
import { BackHeader, Icon } from '../components/ui';

/* ------------------------- Blueprint canvas ------------------------- */

const ROOM_GLYPHS: Record<string, React.ReactNode> = {
  door: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M3 21h18M6 21V6a2 2 0 012-2h8a2 2 0 012 2v15" /><circle cx="14" cy="13" r="1" fill="currentColor" /></svg>,
  clipboard: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><rect x="5" y="4.5" width="14" height="16.5" rx="2" /><path d="M9 4.5V3h6v1.5M8.5 11h7M8.5 15h4.5" /></svg>,
  people: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><circle cx="9" cy="8" r="3" /><path d="M3 20a6 6 0 0112 0M16 5.5a3 3 0 010 5.6M18 20a6 6 0 00-2.5-4.9" /></svg>,
  steth: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M5 3v5a4 4 0 008 0V3M9 12v2.5a5 5 0 0010 0V13" /><circle cx="19" cy="11" r="2" /></svg>,
  pills: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><rect x="2.6" y="8.6" width="18.8" height="6.8" rx="3.4" transform="rotate(-45 12 12)" /><path d="M8.4 8.4l7.2 7.2" /></svg>,
  card: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><rect x="2.5" y="5" width="19" height="14" rx="2.5" /><path d="M2.5 10h19" /></svg>,
  person: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><circle cx="12" cy="6" r="3" /><path d="M7 21v-3a5 5 0 0110 0v3" /></svg>,
  lift: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><rect x="4" y="3" width="16" height="18" rx="2" /><path d="M9.5 9.5L12 7l2.5 2.5M9.5 14.5L12 17l2.5-2.5" /></svg>,
  case: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><rect x="3" y="7" width="18" height="13" rx="2.5" /><path d="M9 7V5.5A1.5 1.5 0 0110.5 4h3A1.5 1.5 0 0115 5.5V7M12 11v5M9.5 13.5h5" /></svg>,
  heart: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M12 20s-7-4.6-7-9.6A4.4 4.4 0 0112 7a4.4 4.4 0 017 3.4c0 5-7 9.6-7 9.6z" /></svg>,
  testtube: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M9 3h6M10 3v6.2L5.4 18a2 2 0 001.7 3h9.8a2 2 0 001.7-3L14 9.2V3M7.5 14h9" /></svg>,
  shield: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M12 3l7 3v6c0 4.4-3 7.6-7 9-4-1.4-7-4.6-7-9V6l7-3z" /><path d="M9.5 12l2 2 3.5-3.5" /></svg>,
  drop: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M12 3s6 6.3 6 10.2A6 6 0 016 13.2C6 9.3 12 3 12 3z" /></svg>,
  vial: <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M8 3h8M12 3v13M8 16a4 4 0 008 0V7H8v9z" /></svg>,
};

const glyphFor = (icon: string) => {
  if (icon.includes('door')) return ROOM_GLYPHS.door;
  if (icon.includes('clipboard')) return ROOM_GLYPHS.clipboard;
  if (icon.includes('person.3')) return ROOM_GLYPHS.people;
  if (icon.includes('person')) return ROOM_GLYPHS.person;
  if (icon.includes('stethoscope')) return ROOM_GLYPHS.steth;
  if (icon.includes('pills')) return ROOM_GLYPHS.pills;
  if (icon.includes('creditcard')) return ROOM_GLYPHS.card;
  if (icon.includes('arrow.up')) return ROOM_GLYPHS.lift;
  if (icon.includes('cross.case')) return ROOM_GLYPHS.case;
  if (icon.includes('heart')) return ROOM_GLYPHS.heart;
  if (icon.includes('testtube')) return ROOM_GLYPHS.testtube;
  if (icon.includes('shield')) return ROOM_GLYPHS.shield;
  if (icon.includes('drop')) return ROOM_GLYPHS.drop;
  if (icon.includes('vial')) return ROOM_GLYPHS.vial;
  return ROOM_GLYPHS.person;
};

export function MapCanvas({
  height = 380, targetRoomName, interactive = false, onSelectRoom,
}: {
  height?: number;
  targetRoomName?: string;
  interactive?: boolean;
  onSelectRoom?: (r: ClinicRoom) => void;
}) {
  const target = targetRoomName ? roomByName(targetRoomName) : undefined;
  const floor = target ? (upperRooms.some((r) => r.name === target.name) ? 2 : 1) : 1;
  const rooms = roomsForFloor(floor as 1 | 2);
  const [selectedId, setSelectedId] = useState<string | null>(null);

  const entrance = rooms.find((r) => r.isEntrance);
  const dest = interactive
    ? rooms.find((r) => r.id === selectedId)
    : target;
  const from = entrance;

  const path = useMemo(() => {
    if (!dest || !from) return null;
    const corridorY = 0.135;
    return `M ${from.rect.x + from.rect.w / 2} ${from.rect.y + from.rect.h / 2}
            L ${from.rect.x + from.rect.w / 2} ${corridorY}
            L ${dest.rect.x + dest.rect.w / 2} ${corridorY}
            L ${dest.rect.x + dest.rect.w / 2} ${dest.rect.y + dest.rect.h / 2}`;
  }, [dest, from]);

  return (
    <div className="blueprint" style={{ height, width: '100%' }}>
      {/* blueprint grid */}
      <div style={{
        position: 'absolute', inset: 0,
        backgroundImage:
          'linear-gradient(rgba(255,255,255,.035) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.035) 1px, transparent 1px)',
        backgroundSize: '18px 18px',
      }} />

      {/* corridor */}
      <div style={{ position: 'absolute', left: 0, right: 0, top: '13.5%', height: 1, background: 'rgba(255,255,255,.07)' }} />
      <div className="corridor-label" style={{ top: '11.4%' }}>— MAIN CORRIDOR —</div>

      {/* route path */}
      {path && dest && (
        <svg viewBox="0 0 1 1" preserveAspectRatio="none" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', overflow: 'visible' }}>
          <path d={path} fill="none" stroke={dest.accent} strokeOpacity=".25" strokeWidth="6"
            style={{ filter: 'blur(3px)' }} vectorEffect="non-scaling-stroke" />
          <path d={path} fill="none" stroke={COLORS.mint} strokeWidth="2.2" strokeDasharray="7 5" strokeLinecap="round"
            vectorEffect="non-scaling-stroke" />
        </svg>
      )}

      {/* rooms */}
      {rooms.map((r) => {
        const isTarget = dest?.id === r.id;
        const isEntrance = r.isEntrance;
        return (
          <div
            key={r.id}
            className="room"
            onClick={() => {
              if (!interactive) return;
              setSelectedId((s) => (s === r.id ? null : r.id));
              onSelectRoom?.(r);
            }}
            style={{
              left: `${r.rect.x * 100}%`,
              top: `${r.rect.y * 100}%`,
              width: `${r.rect.w * 100}%`,
              height: `${r.rect.h * 100}%`,
              background: isTarget
                ? `${r.accent}38`
                : isEntrance ? 'rgba(13,200,164,.10)' : 'rgba(255,255,255,.04)',
              border: `1px solid ${isTarget ? r.accent : isEntrance ? 'rgba(13,200,164,.7)' : 'rgba(255,255,255,.18)'}`,
              boxShadow: isTarget ? `0 0 0 4px ${r.accent}33, 0 0 16px ${r.accent}55` : 'none',
              cursor: interactive ? 'pointer' : 'default',
            }}
          >
            <span style={{ color: isTarget ? r.accent : isEntrance ? COLORS.mint : 'rgba(255,255,255,.7)', display: 'flex' }}>
              {glyphFor(r.icon)}
            </span>
            <span className="rname" style={{ color: isTarget ? '#fff' : 'rgba(255,255,255,.68)' }}>{r.name}</span>
            <span className="rnum">{r.number}</span>
          </div>
        );
      })}

      {/* entrance pulse */}
      {from && (
        <div style={{
          position: 'absolute',
          left: `${(from.rect.x + from.rect.w / 2) * 100}%`,
          top: `${(from.rect.y + from.rect.h / 2) * 100}%`,
          transform: 'translate(-50%,-50%)',
          width: 22, height: 22, borderRadius: '50%',
          background: 'rgba(13,200,164,.22)',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          animation: 'pulse 2s ease-in-out infinite',
        }}>
          <span style={{ width: 9, height: 9, borderRadius: '50%', background: COLORS.mint, border: '1.5px solid #fff' }} />
        </div>
      )}

      {/* destination pin */}
      {dest && !dest.isEntrance && (
        <div style={{
          position: 'absolute',
          left: `${(dest.rect.x + dest.rect.w / 2) * 100}%`,
          top: `${(dest.rect.y + dest.rect.h / 2) * 100}%`,
          transform: 'translate(-50%,-50%)',
          width: 12, height: 12, borderRadius: '50%',
          background: dest.accent, border: '2px solid #fff',
          boxShadow: `0 0 12px ${dest.accent}`,
        }} />
      )}

      <style>{`@keyframes pulse { 0%,100% { transform: translate(-50%,-50%) scale(.8); opacity:.85 } 50% { transform: translate(-50%,-50%) scale(1.35); opacity:.35 } }`}</style>
    </div>
  );
}

/* ---------------------------- Map screen ---------------------------- */

function DirectionCard({ room, floor }: { room: ClinicRoom; floor: 1 | 2 }) {
  const steps = floor === 1
    ? ['Start at Main Entrance', 'Walk along the main corridor', `Follow the ${room.name === 'Pharmacy' ? 'orange' : 'blue'} signs`, `Room ${room.number} — ${room.name}`]
    : ['Take Elevator to Floor 2', 'Exit and walk along corridor', `Room ${room.number} is on your ${room.rect.x < 0.5 ? 'left' : 'right'}`, `Arrive at ${room.name}`];

  return (
    <div className="fade-in" style={{ background: '#fff', borderRadius: 18, overflow: 'hidden', border: '1px solid #E8ECF2', boxShadow: '0 6px 20px -12px rgba(15,23,42,.3)' }}>
      <div style={{ display: 'flex' }}>
        <div style={{ width: 4, background: room.accent, flex: '0 0 auto' }} />
        <div style={{ padding: '13px 14px', flex: 1 }}>
          <div className="row gap-12">
            <div style={{ width: 40, height: 40, borderRadius: 11, background: `${room.accent}22`, color: room.accent, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              {glyphFor(room.icon)}
            </div>
            <div className="grow">
              <div style={{ fontSize: 15, fontWeight: 800, color: COLORS.navy }}>{room.name}</div>
              <div className="xs muted" style={{ marginTop: 2 }}>Room {room.number} · {room.walkMins} min walk</div>
            </div>
            <div style={{ textAlign: 'center', color: COLORS.mint }}>
              {Icon.walk({ size: 15 })}
              <div className="xs" style={{ fontWeight: 800 }}>{room.walkMins}m</div>
            </div>
          </div>
        </div>
      </div>
      <div style={{ borderTop: '1px solid #F1F5F9', padding: '10px 14px 12px', background: '#F8FAFC' }}>
        {steps.map((s, i) => (
          <div key={s} className="row gap-8" style={{ marginBottom: i < steps.length - 1 ? 7 : 0 }}>
            <span style={{
              width: 18, height: 18, borderRadius: '50%', flex: '0 0 auto',
              background: i === steps.length - 1 ? room.accent : 'rgba(13,200,164,.13)',
              color: i === steps.length - 1 ? '#fff' : COLORS.mint,
              fontSize: 8.5, fontWeight: 800,
              display: 'flex', alignItems: 'center', justifyContent: 'center',
            }}>{i === steps.length - 1 ? Icon.check({ size: 10, color: '#fff' }) : i + 1}</span>
            <span className="xs" style={{ color: i === steps.length - 1 ? COLORS.navy : 'rgba(26,46,68,.62)', fontWeight: i === steps.length - 1 ? 700 : 500 }}>{s}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

export function MapScreen() {
  const { back } = useClinic();
  const [floor, setFloor] = useState<1 | 2>(1);
  const [selected, setSelected] = useState<ClinicRoom | null>(null);

  const rooms = roomsForFloor(floor);

  return (
    <Screen>
      <div style={{ padding: '6px 0 30px', minHeight: '100%', background: '#F0F4F8' }}>
        <div style={{ padding: '0 16px' }}>
          <BackHeader title="Indoor Map" onBack={back} />
        </div>

        <div style={{ padding: '4px 16px 14px' }}>
          <div className="floor-toggle">
            {([1, 2] as const).map((f) => (
              <button
                key={f}
                className={floor === f ? 'active' : ''}
                onClick={() => { setFloor(f); setSelected(null); }}
              >
                <span className="bubble">{f === 1 ? 'G' : '2'}</span>
                <span className="fname">{f === 1 ? 'Ground Floor' : '2nd Floor'}</span>
              </button>
            ))}
          </div>
        </div>

        <div style={{ padding: '0 16px 14px' }}>
          <MapCanvas
            key={floor}
            height={330}
            interactive
            onSelectRoom={(r) => setSelected((cur) => (cur?.id === r.id ? null : r))}
          />
        </div>

        <div className="row gap-6" style={{ padding: '0 20px 14px', flexWrap: 'wrap' }}>
          <Legend dot={COLORS.mint} pulse label="You are here" />
          <Legend dot={COLORS.mint} label="Path" />
          <Legend dot="rgba(255,255,255,.35)" label="Tap room" />
        </div>

        <div style={{ padding: '0 16px' }}>
          {selected ? (
            <DirectionCard room={selected} floor={floor} />
          ) : (
            <div className="row gap-12" style={{ padding: 15, background: '#fff', borderRadius: 14, border: '1px solid #E8ECF2' }}>
              <span style={{ color: COLORS.mint, display: 'flex' }}>{Icon.tap({ size: 20 })}</span>
              <div>
                <div style={{ fontSize: 13.5, fontWeight: 800, color: COLORS.navy }}>Find your destination</div>
                <div className="xs muted" style={{ marginTop: 2 }}>Tap any room on the map to get directions</div>
              </div>
            </div>
          )}
        </div>
      </div>
    </Screen>
  );
}

function Legend({ dot, label, pulse }: { dot: string; label: string; pulse?: boolean }) {
  return (
    <div className="row gap-6" style={{ marginRight: 10 }}>
      <span style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'center', width: 10, height: 10 }}>
        {pulse && <span style={{ position: 'absolute', inset: -3, borderRadius: '50%', background: `${COLORS.mint}44` }} />}
        <span style={{ width: 7, height: 7, borderRadius: '50%', background: dot }} />
      </span>
      <span style={{ fontSize: 10.5, color: '#64748b' }}>{label}</span>
    </div>
  );
}
