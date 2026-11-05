import React, { useMemo, useState } from 'react';
import { useClinic, totalFor } from '../data/store';
import { drugs, COLORS, money, ADMIN_FEE } from '../data/clinic';
import { Screen, FloatingNav } from '../components/shell';
import { BackHeader, Icon, PrimaryButton, SearchField, Badge } from '../components/ui';
import { PayOption } from './clinicFlow';

type CartLine = { id: string; name: string; subtitle: string; price: number; image: string; qty: number };

export function PharmacyScreen() {
  const { push, back } = useClinic();
  const [search, setSearch] = useState('');
  const [qty, setQty] = useState<Record<string, number>>({});
  const [uploadOpen, setUploadOpen] = useState(false);
  const [prescription, setPrescription] = useState<string | null>(null);

  const filtered = drugs.filter((d) => !search || d.name.toLowerCase().includes(search.toLowerCase()));
  const cartCount = Object.values(qty).reduce((a, b) => a + b, 0);

  const openCart = () => {
    const lines: CartLine[] = Object.entries(qty)
      .filter(([, n]) => n > 0)
      .map(([id, n]) => {
        const d = drugs.find((x) => x.id === id)!;
        return { id, name: d.name, subtitle: d.subtitle, price: d.price, image: d.image, qty: n };
      });
    push('cart', { lines, prescription });
  };

  return (
    <Screen fab={<FloatingNav />}>
      <div style={{ padding: '6px 16px 110px' }}>
        <div className="between" style={{ marginBottom: 14 }}>
          <button className="back-btn" onClick={back}>{Icon.back({ size: 18 })}</button>
          <div className="h3">Pharmacy</div>
          <button onClick={openCart} style={{ position: 'relative', color: COLORS.navy }}>
            {Icon.cart({ size: 21 })}
            {cartCount > 0 && (
              <span style={{
                position: 'absolute', top: -6, right: -6, minWidth: 16, height: 16, borderRadius: 9,
                background: COLORS.mint, color: '#fff', fontSize: 9.5, fontWeight: 800,
                display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '0 4px',
              }}>{cartCount}</span>
            )}
          </button>
        </div>

        <SearchField value={search} onChange={setSearch} placeholder="Find a medicine" />

        {/* Prescription banner */}
        <div style={{
          marginTop: 16, borderRadius: 18, padding: '20px 16px',
          background: 'linear-gradient(135deg, #E6F9F5, #D0F0FF)',
          display: 'flex', alignItems: 'center', gap: 10,
          boxShadow: '0 8px 22px -16px rgba(13,200,164,.8)',
        }}>
          <div className="grow">
            <div style={{ fontSize: 16, fontWeight: 800, color: COLORS.navy, lineHeight: 1.35 }}>
              Order quickly with<br />prescription
            </div>
            <button
              onClick={() => setUploadOpen(true)}
              style={{ marginTop: 12, padding: '10px 18px', borderRadius: 24, background: COLORS.primary, color: '#fff', fontSize: 13, fontWeight: 700 }}
            >
              Upload Prescription
            </button>
          </div>
          <div style={{ position: 'relative', width: 84, height: 84, flex: '0 0 auto' }}>
            <div style={{ position: 'absolute', inset: 6, borderRadius: '50%', background: 'rgba(13,200,164,.16)' }} />
            <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 1 }}>
              <span style={{ color: '#ef4444', fontWeight: 900, fontSize: 17 }}>✚</span>
              <span style={{ color: '#9dc6ef', fontSize: 30, lineHeight: 1 }}>▤</span>
              <span style={{ color: '#f2c200', fontSize: 15, lineHeight: 1 }}>▮</span>
            </div>
          </div>
        </div>

        <div className="h2" style={{ marginTop: 22, marginBottom: 12 }}>Popular Products</div>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          {filtered.map((d) => {
            const n = qty[d.id] ?? 0;
            return (
              <div key={d.id} style={{ background: '#fff', borderRadius: 16, padding: 11, boxShadow: '0 4px 14px -10px rgba(15,23,42,.4)' }}>
                <div style={{
                  height: 100, borderRadius: 12, background: 'rgba(13,200,164,.07)',
                  display: 'flex', alignItems: 'center', justifyContent: 'center', marginBottom: 9,
                }}>
                  <img src={d.image} alt={d.name} style={{ width: 72, height: 72, objectFit: 'contain' }} />
                </div>
                <div style={{ fontSize: 12.8, fontWeight: 800, lineHeight: 1.25, minHeight: 32 }}>{d.name}</div>
                <div className="xs muted" style={{ marginTop: 2, marginBottom: 8 }}>{d.subtitle}</div>
                <div className="between">
                  <span style={{ fontSize: 14, fontWeight: 800 }}>{money(d.price)}</span>
                  {n === 0 ? (
                    <button
                      onClick={() => setQty((q) => ({ ...q, [d.id]: 1 }))}
                      style={{ width: 28, height: 28, borderRadius: 7, background: COLORS.navy, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}
                    >{Icon.plus({ size: 14 })}</button>
                  ) : (
                    <div className="stepper" style={{ height: 28 }}>
                      <button style={{ width: 26, height: 28, fontSize: 15 }} onClick={() => setQty((q) => ({ ...q, [d.id]: Math.max(0, (q[d.id] ?? 0) - 1) }))}>−</button>
                      <span style={{ width: 26, fontSize: 12 }}>{String(n).padStart(2, '0')}</span>
                      <button style={{ width: 26, height: 28, fontSize: 15 }} onClick={() => setQty((q) => ({ ...q, [d.id]: (q[d.id] ?? 0) + 1 }))}>+</button>
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {uploadOpen && (
        <div className="overlay" onClick={() => setUploadOpen(false)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()} style={{ padding: 0, overflow: 'hidden' }}>
            <div style={{ padding: '20px 20px 0' }}>
              <div className="h3" style={{ textAlign: 'center', marginBottom: 4 }}>Upload Prescription</div>
              <p className="xs muted" style={{ textAlign: 'center', marginBottom: 14 }}>
                A pharmacist will review it before dispensing
              </p>
              <label
                style={{
                  height: 150, borderRadius: 16, border: '1.5px dashed #cbd5e1', background: '#f8fafc',
                  display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 8, cursor: 'pointer',
                  overflow: 'hidden', position: 'relative',
                }}
              >
                <input
                  type="file" accept="image/*" style={{ display: 'none' }}
                  onChange={(e) => {
                    const f = e.target.files?.[0];
                    if (f) setPrescription(f.name);
                  }}
                />
                {prescription ? (
                  <>
                    <span style={{ color: COLORS.primary }}>{Icon.doc({ size: 28 })}</span>
                    <span className="small bold" style={{ maxWidth: 210, textAlign: 'center', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{prescription}</span>
                    <span className="xs muted">Tap to replace</span>
                  </>
                ) : (
                  <>
                    <span style={{ width: 46, height: 46, borderRadius: '50%', background: '#e9eef4', display: 'flex', alignItems: 'center', justifyContent: 'center', color: COLORS.navy }}>
                      {Icon.plus({ size: 22 })}
                    </span>
                    <span className="small bold">Drag &amp; drop files here</span>
                    <span className="xs muted">or Select Browse</span>
                  </>
                )}
              </label>
            </div>
            <div className="row gap-12" style={{ padding: 20 }}>
              <button
                className="grow"
                onClick={() => setUploadOpen(false)}
                style={{ padding: '13px 0', borderRadius: 30, border: '1.5px solid #e2e8f0', fontWeight: 700, fontSize: 14 }}
              >Cancel</button>
              <button
                className="grow"
                onClick={() => setUploadOpen(false)}
                style={{ padding: '13px 0', borderRadius: 30, background: COLORS.primary, color: '#fff', fontWeight: 700, fontSize: 14 }}
              >Next</button>
            </div>
          </div>
        </div>
      )}
    </Screen>
  );
}

/* --------------------------------- Cart -------------------------------- */

export function CartScreen({ lines, prescription }: { lines?: CartLine[]; prescription?: string | null }) {
  const { push, back } = useClinic();
  const [items, setItems] = useState<CartLine[]>(lines && lines.length ? lines : [
    { id: 'c1', name: 'PANADOL TABLETS', subtitle: '20pcs', price: 10.99, image: '/assets/drugs/PANADOL.png', qty: 1 },
    { id: 'c2', name: 'Amoxicillin', subtitle: '500mg · 30 caps', price: 14.99, image: '/assets/drugs/Amoxicillin.png', qty: 1 },
  ]);

  const total = items.reduce((s, i) => s + i.price * i.qty, 0);
  const fileName = prescription ?? `screenshot${new Date().toISOString().slice(0, 10).replace(/-/g, '')}.....JPG`;

  return (
    <Screen>
      <div style={{ padding: '6px 16px 130px' }}>
        <BackHeader
          title="My Cart"
          onBack={back}
          right={<span style={{ color: COLORS.primary }}>{Icon.cart({ size: 20 })}</span>}
        />

        <div style={{ marginTop: 12 }}>
          <div className="h3" style={{ color: COLORS.navy, marginBottom: 10 }}>Prescription</div>
          <div className="row gap-12" style={{ padding: 12, background: '#fff', borderRadius: 12, border: '1px solid #d8e0e8' }}>
            <div style={{ width: 46, height: 46, borderRadius: 9, background: '#f1f5f9', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#64748b' }}>
              {Icon.doc({ size: 20 })}
            </div>
            <div className="grow" style={{ minWidth: 0 }}>
              <div className="small semi" style={{ color: COLORS.navy, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{fileName}</div>
              <div className="xs muted" style={{ marginTop: 2 }}>Attached to this order</div>
            </div>
          </div>
        </div>

        <div className="stack gap-12" style={{ marginTop: 18 }}>
          {items.map((it, idx) => (
            <div key={it.id} className="row gap-12" style={{ background: '#fff', borderRadius: 16, padding: 12, boxShadow: '0 4px 14px -10px rgba(15,23,42,.4)' }}>
              <img src={it.image} alt="" style={{ width: 64, height: 64, objectFit: 'contain', borderRadius: 10, background: '#f8fafc' }} />
              <div className="grow" style={{ minWidth: 0 }}>
                <div style={{ fontSize: 13.5, fontWeight: 800, color: COLORS.navy }}>{it.name}</div>
                <div className="xs muted" style={{ marginTop: 2 }}>{it.subtitle}</div>
                <div className="between" style={{ marginTop: 6 }}>
                  <span style={{ fontSize: 13.5, fontWeight: 800, color: COLORS.navy }}>{money(it.price * it.qty)}</span>
                  <div className="stepper">
                    <button onClick={() => setItems((l) => l.map((x, i) => i === idx ? { ...x, qty: Math.max(1, x.qty - 1) } : x))}>−</button>
                    <span>{String(it.qty).padStart(2, '0')}</span>
                    <button onClick={() => setItems((l) => l.map((x, i) => i === idx ? { ...x, qty: x.qty + 1 } : x))}>+</button>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>

        <div className="between" style={{ marginTop: 20, padding: '14px 16px', borderRadius: 14, background: '#f6f9fc' }}>
          <span className="small bold">Subtotal</span>
          <span className="h3">{money(total)}</span>
        </div>
      </div>

      <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, padding: '14px 24px 24px', background: '#fff', boxShadow: '0 -6px 18px -16px rgba(15,23,42,.5)' }}>
        <PrimaryButton onClick={() => push('pharPayment', { total })}>Check Out</PrimaryButton>
      </div>
    </Screen>
  );
}

/* ----------------------------- Pharmacy pay ---------------------------- */

export function PharPaymentScreen({ total }: { total?: number }) {
  const { push, back, setPayment } = useClinic();
  const subtotal = total ?? 25.98;
  const admin = ADMIN_FEE;
  const grand = totalFor(subtotal, admin);
  const [method, setMethod] = useState<'card' | 'cash'>('card');

  return (
    <Screen>
      <div style={{ padding: '6px 18px 30px' }}>
        <BackHeader title="Payment" onBack={back} />

        <div style={{ marginTop: 16 }}>
          <div className="h2" style={{ marginBottom: 14 }}>Payment Details</div>
          {[
            { label: 'Pharmacy Items', value: money(subtotal) },
            { label: 'Admin Fee', value: money(admin) },
            { label: 'Additional Discount', value: money(0) },
            { label: 'Total', value: money(grand), total: true },
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
            <button style={{ width: '100%' }} onClick={() => setMethod('card')}>
              <PayOption label="Card Payment" icon="/assets/pay/card.png" selected={method === 'card'} />
            </button>
            <button style={{ width: '100%' }} onClick={() => setMethod('cash')}>
              <PayOption label="Cash Payment" icon="/assets/pay/cash.png" selected={method === 'cash'} />
            </button>
          </div>
        </div>

        <div style={{ marginTop: 24 }}>
          <PrimaryButton
            onClick={() => {
              setPayment({
                title: 'Pharmacy', label: 'Pharmacy Items', amount: subtotal, adminFee: admin,
                method, onSuccessScreen: 'pharmacy',
              });
              push(method === 'card' ? 'payment' : 'cashPay');
            }}
          >
            Pay Now
          </PrimaryButton>
        </div>

        <div className="card soft" style={{ marginTop: 18 }}>
          <div className="row gap-10">
            <span style={{ color: COLORS.primary }}>{Icon.info({ size: 18 })}</span>
            <div className="xs muted" style={{ lineHeight: 1.55 }}>
              Pick up at the pharmacy counter (Room G04) once your queue number is called.
            </div>
          </div>
        </div>
      </div>
    </Screen>
  );
}
