import React, { useEffect, useMemo, useState } from 'react';
import { useClinic, totalFor } from '../data/store';
import {
  labCategories, imagingCategories, labTests, imagingTests, ADMIN_FEE, COLORS, money,
  ClinicStep, uid,
} from '../data/clinic';
import { Screen, FloatingNav } from '../components/shell';
import {
  BackHeader, SearchField, Icon, PrimaryButton, Badge, SectionHeading, EmptyState, QRCode,
} from '../components/ui';
import { testImageFor, PayOption } from './clinicFlow';
import { MapCanvas } from './map';

/* ---------------------------- test list card ---------------------------- */

function SelectableTestCard({
  step, selected, onToggle, showExtra,
}: { step: ClinicStep; selected: boolean; onToggle: () => void; showExtra: boolean }) {
  return (
    <div
      className={`test-card ${selected ? 'selected' : ''}`}
      onClick={onToggle}
      style={{ cursor: 'pointer', position: 'relative' }}
    >
      <div className="row gap-14" style={{ alignItems: 'flex-start' }}>
        <img src={testImageFor(step.name)} alt="" className="thumb" />
        <div className="grow">
          <div style={{ fontSize: 15, fontWeight: 800, lineHeight: 1.3 }}>{step.name}</div>
          <div className="xs" style={{ color: '#4a9c82', fontWeight: 700, marginTop: 4 }}>Available Now</div>
          <div className="xs muted" style={{ marginTop: 5 }}>Estimated wait: {step.estimatedWait}</div>
          <div className="xs muted" style={{ marginTop: 2 }}>Location: {step.location}</div>
        </div>
      </div>

      {showExtra && (
        <>
          <div className="divider" style={{ margin: '13px 0 11px' }} />
          <div className="between" style={{ alignItems: 'flex-start' }}>
            <div>
              <div className="xs bold" style={{ marginBottom: 4 }}>Requirements</div>
              {(step.requirements ?? []).map((r) => (
                <div key={r} className="xs muted" style={{ lineHeight: 1.6 }}>• {r}</div>
              ))}
            </div>
            <div style={{ textAlign: 'right' }}>
              <div className="xs bold">Approx Time</div>
              <div className="xs muted" style={{ marginTop: 4 }}>{step.estimatedWait}</div>
            </div>
          </div>
        </>
      )}

      <div className="row" style={{ marginTop: 14, justifyContent: 'center' }}>
        <span className="price-pill" style={{ padding: '7px 20px' }}>Fee: {money(step.price ?? 0)}</span>
      </div>

      {selected && (
        <span style={{
          position: 'absolute', top: 12, right: 12, width: 24, height: 24, borderRadius: '50%',
          background: COLORS.primary, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center',
          boxShadow: '0 0 0 3px #fff',
        }}>{Icon.check({ size: 14 })}</span>
      )}
    </div>
  );
}

function CategoryGridSmall({
  items, selected, onSelect,
}: { items: { id: string; title: string; icon: string }[]; selected: string | null; onSelect: (t: string | null) => void }) {
  return (
    <div className="cat-grid">
      {items.map((c) => (
        <button
          key={c.id}
          className={`cat-box ${selected === c.title ? 'selected' : ''}`}
          onClick={() => onSelect(selected === c.title ? null : c.title)}
        >
          <img src={c.icon} alt="" />
          <span>{c.title}</span>
        </button>
      ))}
    </div>
  );
}

/* ------------------------------- Lab list ------------------------------- */

export function LabListScreen() {
  const { push, back, setCurrentService } = useClinic();
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [category, setCategory] = useState<string | null>(null);
  const [search, setSearch] = useState('');

  useEffect(() => setCurrentService('lab'), [setCurrentService]);

  const filtered = labTests.filter((t) => !search || t.name.toLowerCase().includes(search.toLowerCase()));
  const selectedCards = filtered.filter((t) => selected.has(t.id));
  const available = filtered.filter((t) => !selected.has(t.id));
  const toggle = (id: string) => setSelected((s) => {
    const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n;
  });

  return (
    <Screen>
      <div style={{ padding: '6px 14px 110px' }}>
        <BackHeader title={category ?? 'Laboratory'} onBack={back} />
        <div style={{ marginTop: 8 }}>
          <SearchField value={search} onChange={setSearch} placeholder="Find a Lab Test" />
        </div>

        <div className="h2" style={{ marginTop: 24, marginBottom: 12 }}>Category</div>
        <CategoryGridSmall items={labCategories} selected={category} onSelect={setCategory} />

        {selectedCards.length > 0 && (
          <>
            <div className="h2" style={{ marginTop: 24, marginBottom: 12 }}>Selected Tests</div>
            <div className="stack gap-12">
              {selectedCards.map((t) => (
                <SelectableTestCard key={t.id} step={t} selected onToggle={() => toggle(t.id)} showExtra={false} />
              ))}
            </div>
          </>
        )}

        {available.length > 0 && (
          <>
            <div className="h2" style={{ marginTop: 24, marginBottom: 12 }}>Choose Your Lab Test</div>
            <div className="stack gap-12">
              {available.map((t) => (
                <SelectableTestCard key={t.id} step={t} selected={false} onToggle={() => toggle(t.id)} showExtra={false} />
              ))}
            </div>
          </>
        )}
      </div>

      <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, padding: '14px 20px 22px', background: 'linear-gradient(to bottom, rgba(255,255,255,0), #fff 30%)' }}>
        <PrimaryButton
          disabled={selected.size === 0}
          onClick={() => push('labDetails', { ids: Array.from(selected) })}
        >
          Next
        </PrimaryButton>
      </div>
    </Screen>
  );
}

/* ----------------------------- Lab details ----------------------------- */

export function LabDetailsScreen({ ids }: { ids: string[] }) {
  const { push, back, setPayment } = useClinic();
  const cards = labTests.filter((t) => ids.includes(t.id));
  const totalPrice = cards.reduce((s, c) => s + (c.price ?? 0), 0);
  const total = totalFor(totalPrice, ADMIN_FEE);
  const [method, setMethod] = useState<'card' | 'cash'>('card');

  const book = () => {
    setPayment({
      title: 'Lab Tests',
      label: 'Lab Tests',
      amount: totalPrice,
      adminFee: ADMIN_FEE,
      method,
      onSuccessScreen: 'labTracker',
      onSuccessParams: { ids },
    });
    push(method === 'card' ? 'payment' : 'cashPay');
  };

  return (
    <Screen>
      <div style={{ padding: '6px 18px 120px' }}>
        <BackHeader title="Lab Tests" onBack={back} />
        <div className="stack gap-14" style={{ marginTop: 10 }}>
          {cards.map((t) => (
            <div key={t.id} className="test-card">
              <div className="row gap-14" style={{ alignItems: 'flex-start' }}>
                <img src={testImageFor(t.name)} alt="" className="thumb" />
                <div className="grow">
                  <div style={{ fontSize: 15, fontWeight: 800 }}>{t.name}</div>
                  <div className="xs" style={{ color: '#4a9c82', marginTop: 4, fontWeight: 700 }}>Lab Test</div>
                  <div className="xs muted" style={{ marginTop: 4 }}>Location: {t.location}</div>
                  <div className="xs muted">Wait: {t.estimatedWait}</div>
                </div>
              </div>
              <div className="divider" style={{ margin: '13px 0 11px' }} />
              <div className="between" style={{ alignItems: 'flex-start' }}>
                <div>
                  <div className="xs bold" style={{ marginBottom: 4 }}>Requirements</div>
                  {(t.requirements ?? []).slice(0, 2).map((r) => (
                    <div key={r} className="xs muted" style={{ lineHeight: 1.6 }}>• {r}</div>
                  ))}
                </div>
                <div className="xs bold" style={{ color: COLORS.primary }}>{money(t.price ?? 0)}</div>
              </div>
            </div>
          ))}
        </div>

        <div style={{ marginTop: 24 }}>
          <div className="h2" style={{ marginBottom: 14 }}>Payment Details</div>
          {[
            { label: 'Lab Tests', value: money(totalPrice) },
            { label: 'Admin Fee', value: money(ADMIN_FEE) },
            { label: 'Total', value: money(total), total: true },
          ].map((r) => (
            <div key={r.label} className="between" style={{ marginBottom: 11 }}>
              <span style={{ fontSize: 15, color: (r as any).total ? '#0b0f14' : COLORS.lable, fontWeight: (r as any).total ? 800 : 500 }}>{r.label}</span>
              <span style={{ fontSize: 16, fontWeight: 800 }}>{r.value}</span>
            </div>
          ))}
        </div>

        <div style={{ marginTop: 22 }}>
          <div className="h2" style={{ marginBottom: 12 }}>Payment Option</div>
          <div className="stack gap-10">
            <button onClick={() => setMethod('card')} style={{ width: '100%' }}>
              <PayOption label="Card Payment" icon="/assets/pay/card.png" selected={method === 'card'} />
            </button>
            <button onClick={() => setMethod('cash')} style={{ width: '100%' }}>
              <PayOption label="Cash Payment" icon="/assets/pay/cash.png" selected={method === 'cash'} />
            </button>
          </div>
        </div>

        <div style={{ marginTop: 24 }}>
          <PrimaryButton onClick={book}>Booking</PrimaryButton>
        </div>
      </div>
    </Screen>
  );
}

/* ----------------------------- Imaging list ---------------------------- */

export function ImagingListScreen() {
  const { push, back, setCurrentService } = useClinic();
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [category, setCategory] = useState<string | null>(null);
  const [search, setSearch] = useState('');

  useEffect(() => setCurrentService('imaging'), [setCurrentService]);

  const filtered = imagingTests.filter((t) => !search || t.name.toLowerCase().includes(search.toLowerCase()));
  const selectedCards = filtered.filter((t) => selected.has(t.id));
  const available = filtered.filter((t) => !selected.has(t.id));
  const toggle = (id: string) => setSelected((s) => {
    const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n;
  });

  return (
    <Screen>
      <div style={{ padding: '6px 14px 110px' }}>
        <BackHeader title={category ?? 'Imaging Services'} onBack={back} />
        <div style={{ marginTop: 8 }}>
          <SearchField value={search} onChange={setSearch} placeholder="Find an Imaging Test" />
        </div>

        <div className="h2" style={{ marginTop: 24, marginBottom: 12 }}>Category</div>
        <CategoryGridSmall items={imagingCategories} selected={category} onSelect={setCategory} />

        {selectedCards.length > 0 && (
          <>
            <div className="h2" style={{ marginTop: 24, marginBottom: 12 }}>Selected Tests</div>
            <div className="stack gap-12">
              {selectedCards.map((t) => (
                <SelectableTestCard key={t.id} step={t} selected onToggle={() => toggle(t.id)} showExtra={false} />
              ))}
            </div>
          </>
        )}

        {available.length > 0 && (
          <>
            <div className="h2" style={{ marginTop: 24, marginBottom: 12 }}>Choose Your Imaging Test</div>
            <div className="stack gap-12">
              {available.map((t) => (
                <SelectableTestCard key={t.id} step={t} selected={false} onToggle={() => toggle(t.id)} showExtra={false} />
              ))}
            </div>
          </>
        )}
      </div>

      <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, padding: '14px 20px 22px', background: 'linear-gradient(to bottom, rgba(255,255,255,0), #fff 30%)' }}>
        <PrimaryButton disabled={selected.size === 0} onClick={() => push('imagingDetails', { ids: Array.from(selected) })}>
          Next
        </PrimaryButton>
      </div>
    </Screen>
  );
}

/* --------------------------- Imaging details --------------------------- */

export function ImagingDetailsScreen({ ids }: { ids: string[] }) {
  const { push, back, setPayment } = useClinic();
  const cards = imagingTests.filter((t) => ids.includes(t.id));
  const totalPrice = cards.reduce((s, c) => s + (c.price ?? 0), 0);
  const total = totalFor(totalPrice, ADMIN_FEE);
  const [method, setMethod] = useState<'card' | 'cash'>('card');

  const book = () => {
    setPayment({
      title: 'Imaging Tests', label: 'Imaging Tests', amount: totalPrice, adminFee: ADMIN_FEE,
      method, onSuccessScreen: 'labTracker', onSuccessParams: { ids },
    });
    push(method === 'card' ? 'payment' : 'cashPay');
  };

  return (
    <Screen>
      <div style={{ padding: '6px 18px 120px' }}>
        <BackHeader title="Imaging Tests" onBack={back} />
        <div className="stack gap-14" style={{ marginTop: 10 }}>
          {cards.map((t) => (
            <div key={t.id} className="test-card">
              <div className="row gap-14" style={{ alignItems: 'flex-start' }}>
                <img src={testImageFor(t.name)} alt="" className="thumb" />
                <div className="grow">
                  <div style={{ fontSize: 15, fontWeight: 800 }}>{t.name}</div>
                  <div className="xs muted" style={{ marginTop: 5 }}>Location: {t.location}</div>
                  <div className="xs muted">Wait: {t.estimatedWait}</div>
                </div>
              </div>
            </div>
          ))}
        </div>

        <div style={{ marginTop: 24 }}>
          <div className="h2" style={{ marginBottom: 14 }}>Payment Details</div>
          {[
            { label: 'Imaging Tests', value: money(totalPrice) },
            { label: 'Admin Fee', value: money(ADMIN_FEE) },
            { label: 'Total', value: money(total), total: true },
          ].map((r) => (
            <div key={r.label} className="between" style={{ marginBottom: 11 }}>
              <span style={{ fontSize: 15, color: (r as any).total ? '#0b0f14' : COLORS.lable, fontWeight: (r as any).total ? 800 : 500 }}>{r.label}</span>
              <span style={{ fontSize: 16, fontWeight: 800 }}>{r.value}</span>
            </div>
          ))}
        </div>

        <div style={{ marginTop: 22 }}>
          <div className="h2" style={{ marginBottom: 12 }}>Payment Option</div>
          <div className="stack gap-10">
            <button onClick={() => setMethod('card')} style={{ width: '100%' }}>
              <PayOption label="Card Payment" icon="/assets/pay/card.png" selected={method === 'card'} />
            </button>
            <button onClick={() => setMethod('cash')} style={{ width: '100%' }}>
              <PayOption label="Cash Payment" icon="/assets/pay/cash.png" selected={method === 'cash'} />
            </button>
          </div>
        </div>

        <div style={{ marginTop: 24 }}>
          <PrimaryButton onClick={book}>Booking</PrimaryButton>
        </div>
      </div>
    </Screen>
  );
}

/* --------------------------- Lab queue tracker ------------------------- */

function TrackerRow({
  step, index, state,
}: { step: ClinicStep; index: number; state: 'waiting' | 'ready' | 'done' }) {
  const dotCls = state === 'done' ? 'done' : state === 'ready' ? 'ready' : '';
  const lineCls = state === 'done' ? 'done' : state === 'ready' ? 'ready' : '';
  const badge = state === 'done' ? 'green' : state === 'ready' ? 'pink' : 'orange';

  return (
    <div className="tl-row">
      <div className="tl-rail">
        <div className={`tl-dot ${dotCls}`}>{state === 'done' ? Icon.check({ size: 14 }) : index + 1}</div>
        <div className={`tl-line ${lineCls}`} />
      </div>
      <div className="tl-body">
        <div style={{ fontSize: 18, fontWeight: 800 }}>Step {String(index + 1).padStart(2, '0')}</div>
        <div className="small muted" style={{ marginTop: 3 }}>{step.location}</div>
        <div style={{ marginTop: 7 }}>
          <Badge tone={badge as any}>{state === 'done' ? 'completed' : state === 'ready' ? 'Ready' : 'Waiting'}</Badge>
        </div>

        <div className="h3" style={{ marginTop: 16, fontSize: 15 }}>Services</div>
        <div className="card" style={{ marginTop: 8, borderRadius: 16, padding: 12 }}>
          <div className="row gap-12">
            <img src={testImageFor(step.name)} alt="" style={{ width: 60, height: 60, borderRadius: 12, objectFit: 'cover' }} />
            <div>
              <div style={{ fontSize: 14, fontWeight: 800, lineHeight: 1.25 }}>{step.name}</div>
              <div className="xs muted" style={{ marginTop: 3 }}>Est. wait {step.estimatedWait}</div>
              <div className="xs" style={{ color: COLORS.primary, fontWeight: 700, marginTop: 3 }}>{money(step.price ?? 0)}</div>
            </div>
          </div>
        </div>

        <div className="h3" style={{ marginTop: 15, fontSize: 15 }}>Location</div>
        <div style={{ marginTop: 8 }}>
          <MapCanvas height={176} targetRoomName={step.location} />
        </div>
      </div>
    </div>
  );
}

export function LabTrackerScreen({ ids }: { ids: string[] }) {
  const { back } = useClinic();
  const steps = useMemo(() => [...labTests, ...imagingTests].filter((t) => ids.includes(t.id)), [ids]);
  const [phase, setPhase] = useState<'waiting' | 'ready' | 'done'>('waiting');

  useEffect(() => {
    const t1 = setTimeout(() => setPhase('ready'), 6000);
    const t2 = setTimeout(() => setPhase('done'), 14000);
    return () => { clearTimeout(t1); clearTimeout(t2); };
  }, []);

  return (
    <Screen>
      <div style={{ paddingBottom: 40 }}>
        <BackHeader title="Queue Tracker" onBack={back} />

        <div
          className="queue-hero"
          style={{ backgroundImage: 'url(/assets/ui/hero-bg.png)', marginTop: 4, paddingTop: 26, paddingBottom: 30 }}
        >
          {phase === 'done' ? (
            <>
              <div style={{ width: 92, height: 92, borderRadius: '50%', background: 'rgba(255,255,255,.92)', color: COLORS.primary, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                {Icon.check({ size: 44 })}
              </div>
              <div style={{ fontSize: 17, fontWeight: 700, textAlign: 'center', maxWidth: 280 }}>
                All your tests have been completed successfully.
              </div>
            </>
          ) : (
            <>
              <div style={{ fontSize: 21, fontWeight: 700 }}>{phase === 'ready' ? "It's your turn" : 'Please wait'}</div>
              <div className="big-number">11</div>
              {phase === 'ready' ? (
                <div style={{ textAlign: 'center' }}>
                  <div style={{ fontSize: 15, fontWeight: 700, marginBottom: 10 }}>Show this QR code at the counter</div>
                  <div className="qr-tile"><QRCode content="CLINIC-QUEUE-11" size={92} /></div>
                </div>
              ) : (
                <div style={{ textAlign: 'center' }}>
                  <div style={{ fontSize: 14, fontWeight: 600 }}>Now Serving</div>
                  <div className="black-box" style={{ marginTop: 8 }}>05</div>
                  <div style={{ fontSize: 13, marginTop: 12, opacity: .92 }}>Estimated wait ~15 minutes</div>
                </div>
              )}
            </>
          )}
        </div>

        <div style={{ padding: '22px 22px 0' }}>
          {steps.map((s, i) => (
            <TrackerRow key={s.id} step={s} index={i} state={phase === 'done' ? 'done' : phase === 'ready' && i === 0 ? 'ready' : i === 0 && phase === 'waiting' ? 'waiting' : 'waiting'} />
          ))}
        </div>
      </div>
    </Screen>
  );
}
