import React, { useState } from 'react';
import { useClinic, timeLabel } from '../data/store';
import { COLORS, money, notifications, seedTransactions } from '../data/clinic';
import { Screen } from '../components/shell';
import { BackHeader, Badge, Field, Icon, PrimaryButton, EmptyState } from '../components/ui';

/* ------------------------------- Profile ------------------------------- */

const menu = [
  { title: 'My Profile', icon: Icon.user, grad: ['#4FC3F7', '#0288D1'], screen: 'myProfile' as const },
  { title: 'My Notifications', icon: Icon.bell, grad: ['#7986CB', '#3949AB'], screen: 'notifications' as const },
  { title: 'My Transactions', icon: Icon.envelope, grad: ['#66BB6A', '#2E7D32'], screen: 'transactions' as const },
  { title: 'My Tests', icon: Icon.clipboard, grad: ['#EF5350', '#B71C1C'], screen: 'testHistory' as const },
  { title: 'My Channeling', icon: Icon.stethoscope, grad: ['#FFA726', '#E65100'], screen: 'channeling' as const },
];

export function ProfileScreen() {
  const { push, back, resetTo, session } = useClinic();
  const [logout, setLogout] = useState(false);

  return (
    <Screen>
      <div style={{ paddingBottom: 34 }}>
        <div style={{ padding: '0 16px' }}>
          <BackHeader title="Profile" onBack={back} />
        </div>

        <div className="row gap-14" style={{ padding: '10px 20px 26px' }}>
          <div style={{
            width: 58, height: 58, borderRadius: '50%', background: '#e2e8f0',
            display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#94a3b8',
            border: '2px solid #fff', boxShadow: '0 4px 12px -8px rgba(15,23,42,.5)',
          }}>{Icon.user({ size: 28 })}</div>
          <div>
            <div className="h3">{session.name || 'John Doe'}</div>
            <div className="small muted" style={{ marginTop: 2 }}>
              {session.loginMethod === 'phone' ? `+94 ${session.phoneNumber}` : 'johnDo26@gmail.com'}
            </div>
          </div>
        </div>

        <div className="stack gap-4" style={{ padding: '0 12px' }}>
          {menu.map((m) => (
            <button key={m.title} className="list-row" onClick={() => push(m.screen)}>
              <span className="menu-icon" style={{ background: `linear-gradient(135deg, ${m.grad[0]}, ${m.grad[1]})` }}>
                {m.icon({ size: 19 })}
              </span>
              <span className="small bold grow" style={{ textAlign: 'left' }}>{m.title}</span>
              <span style={{ color: '#cbd5e1', display: 'flex' }}>{Icon.chevron({ size: 17 })}</span>
            </button>
          ))}
          <button className="list-row" onClick={() => setLogout(true)}>
            <span className="menu-icon" style={{ background: 'linear-gradient(135deg, #EF5350, #C62828)' }}>
              {Icon.logout({ size: 19 })}
            </span>
            <span className="small bold grow" style={{ textAlign: 'left', color: '#e5484d' }}>Logout</span>
          </button>
        </div>
      </div>

      {logout && (
        <div className="overlay" onClick={() => setLogout(false)}>
          <div className="modal-card" style={{ textAlign: 'center' }} onClick={(e) => e.stopPropagation()}>
            <div className="h3">Are you Logging out</div>
            <p className="small muted" style={{ margin: '10px 0 18px' }}>Your saved check-in details will remain on this device.</p>
            <div className="row gap-12">
              <button className="grow" style={{ padding: '12px 0', borderRadius: 11, background: '#f1f5f9', fontWeight: 700 }} onClick={() => setLogout(false)}>Cancel</button>
              <button className="grow" style={{ padding: '12px 0', borderRadius: 11, background: '#e5484d', color: '#fff', fontWeight: 700 }} onClick={() => resetTo('landing')}>Logout</button>
            </div>
          </div>
        </div>
      )}
    </Screen>
  );
}

/* ------------------------------ My Profile ----------------------------- */

export function MyProfileScreen() {
  const { back, session } = useClinic();
  const [first, setFirst] = useState('John');
  const [last, setLast] = useState('Doe');
  const [gender, setGender] = useState<string | null>(null);
  const [open, setOpen] = useState(false);
  const [contact, setContact] = useState(session.phoneNumber ? `+94 ${session.phoneNumber}` : '');
  const [dob, setDob] = useState('1994-05-18');
  const [saved, setSaved] = useState(false);

  return (
    <Screen>
      <div style={{ padding: '6px 20px 30px' }}>
        <BackHeader title="My Profile" onBack={back} />

        <div style={{ display: 'flex', justifyContent: 'center', margin: '6px 0 18px' }}>
          <div style={{ position: 'relative' }}>
            <div style={{
              width: 104, height: 104, borderRadius: '50%', background: '#e8eef4',
              display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#94a3b8', overflow: 'hidden',
            }}>
              {Icon.user({ size: 48 })}
            </div>
            <span style={{
              position: 'absolute', right: -2, bottom: -2, width: 30, height: 30, borderRadius: '50%',
              background: '#e5484d', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center',
              border: '3px solid #fff',
            }}>{Icon.plus({ size: 14 })}</span>
          </div>
        </div>

        <div className="stack gap-12">
          <Field placeholder="First Name" value={first} onChange={setFirst} />
          <Field placeholder="Last Name" value={last} onChange={setLast} />

          <div>
            <button
              onClick={() => setOpen((o) => !o)}
              className="row between"
              style={{ width: '100%', padding: '14px 16px', border: `1.5px solid ${open ? COLORS.primary : COLORS.border}`, borderRadius: 100, background: '#fff', fontSize: 15, color: gender ? '#1e1e1e' : COLORS.placeholder }}
            >
              <span>{gender ? gender[0].toUpperCase() + gender.slice(1) : 'Gender'}</span>
              <span style={{ transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .2s', color: '#94a3b8' }}>{Icon.chevron({ size: 16 })}</span>
            </button>
            {open && (
              <div className="fade-in" style={{ marginTop: 8, border: '1px solid #e8ebed', borderRadius: 14, overflow: 'hidden' }}>
                {['male', 'female', 'other'].map((g, i) => (
                  <button key={g} onClick={() => { setGender(g); setOpen(false); }}
                    style={{ display: 'block', width: '100%', textAlign: 'left', padding: '13px 16px', fontSize: 14, color: COLORS.text, background: gender === g ? '#f2f9f7' : '#fff', borderTop: i ? '1px solid #f1f4f7' : 'none' }}>
                    {g[0].toUpperCase() + g.slice(1)}
                  </button>
                ))}
              </div>
            )}
          </div>

          <Field placeholder="Email" value="johnDo26@gmail.com" onChange={() => {}} disabled />
          <Field placeholder="Contact Number" value={contact} onChange={setContact} />

          <div>
            <span className="field-label">Date of Birth</span>
            <input className="field" type="date" value={dob} onChange={(e) => setDob(e.target.value)} />
          </div>
        </div>

        <div style={{ textAlign: 'center', marginTop: 26 }}>
          <div className="h3">Link your profile</div>
          <p className="xs muted" style={{ marginTop: 8, lineHeight: 1.6, padding: '0 16px' }}>
            Linking your profile helps you receive important clinic updates, bills, and access your records across devices.
          </p>
          <div className="row gap-12" style={{ justifyContent: 'center', marginTop: 14 }}>
            <span style={{ width: 42, height: 42, borderRadius: 12, background: '#f1f5f9', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <svg width="18" height="20" viewBox="0 0 384 512" fill="#0b0f14"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>
            </span>
            <span style={{ width: 42, height: 42, borderRadius: 12, background: '#f1f5f9', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <img src="/assets/ui/gmail.png" alt="Gmail" style={{ width: 22, height: 22, objectFit: 'contain' }} />
            </span>
          </div>
        </div>

        <div style={{ marginTop: 22 }}>
          <PrimaryButton onClick={() => { setSaved(true); setTimeout(() => setSaved(false), 1800); }}>
            {saved ? 'Saved ✓' : 'Save'}
          </PrimaryButton>
        </div>
      </div>
    </Screen>
  );
}

/* ---------------------------- Notifications ---------------------------- */

export function NotificationsScreen() {
  const { back } = useClinic();
  return (
    <Screen>
      <div style={{ padding: '6px 18px 30px' }}>
        <BackHeader title="Notifications" onBack={back} />
        <div className="stack gap-20" style={{ marginTop: 12 }}>
          {notifications.map((group) => (
            <div key={group.section}>
              <div className="small bold" style={{ marginBottom: 10, color: '#334155' }}>{group.section}</div>
              <div className="stack gap-10">
                {group.items.map((n) => (
                  <div key={n.id} className="row gap-12" style={{ alignItems: 'flex-start', background: '#fff', borderRadius: 14, padding: 13, border: '1px solid #eef1f5' }}>
                    <span style={{
                      width: 42, height: 42, borderRadius: '50%', flex: '0 0 auto',
                      background: n.avatar === 'blood' ? '#ffe0e0' : n.avatar === 'pills' ? '#e0f0ff' : '#e2ecf7',
                      color: n.avatar === 'blood' ? '#d64545' : '#4a78b5',
                      display: 'flex', alignItems: 'center', justifyContent: 'center',
                    }}>
                      {n.avatar === 'blood' ? <span style={{ color: '#d64545', fontSize: 17 }}>◍</span> : n.avatar === 'pills' ? Icon.pill({ size: 18 }) : Icon.user({ size: 18 })}
                    </span>
                    <div className="grow" style={{ minWidth: 0 }}>
                      <div className="small bold">{n.title}</div>
                      <p className="xs muted" style={{ margin: '4px 0 0', lineHeight: 1.55 }}>{n.description}</p>
                      <div className="xs" style={{ color: '#a5b4c4', marginTop: 6 }}>{n.date} · {n.time}</div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>
    </Screen>
  );
}

/* ---------------------------- Transactions ----------------------------- */

export function TransactionsScreen() {
  const { back, transactions } = useClinic();
  return (
    <Screen>
      <div style={{ padding: '6px 18px 30px' }}>
        <BackHeader title="My Transactions" onBack={back} />
        <div style={{ marginTop: 12, background: '#fff', borderRadius: 14, overflow: 'hidden', border: '1px solid #eef1f5' }}>
          {transactions.map((t, i) => (
            <div key={t.id}>
              <div style={{ padding: '14px 15px' }}>
                <div className="between" style={{ alignItems: 'flex-start' }}>
                  <div>
                    <div className="small bold">{t.title}</div>
                    <div className="xs" style={{ color: '#2563eb', marginTop: 3 }}>{t.doctor}</div>
                    <div className="xs muted" style={{ marginTop: 3 }}>{t.date}</div>
                  </div>
                  <div className="xs semi">Total: {t.total}</div>
                </div>
                <div style={{ marginTop: 9 }}>
                  <Badge tone={t.status === 'Completed' ? 'green' : 'orange'}>{t.status}</Badge>
                </div>
              </div>
              {i < transactions.length - 1 && <div className="divider" />}
            </div>
          ))}
        </div>
      </div>
    </Screen>
  );
}

/* ------------------------------- My tests ------------------------------ */

export function TestHistoryScreen() {
  const { back, testHistory } = useClinic();
  const tone = (s: string) => (s === 'Completed' ? 'green' : s === 'Rescheduled' ? 'orange' : 'blue');
  return (
    <Screen>
      <div style={{ padding: '6px 18px 30px' }}>
        <BackHeader title="My Tests" onBack={back} />
        <div style={{ marginTop: 12, background: '#fff', borderRadius: 14, overflow: 'hidden', border: '1px solid #eef1f5' }}>
          {testHistory.map((t, i) => (
            <div key={t.id}>
              <div style={{ padding: '14px 15px' }}>
                <div className="between" style={{ alignItems: 'flex-start' }}>
                  <div>
                    <div className="small bold">{t.testName}</div>
                    <div className="xs" style={{ color: '#2563eb', marginTop: 3 }}>{t.doctor}</div>
                    <div className="xs muted" style={{ marginTop: 3 }}>{t.date}</div>
                  </div>
                  <div className="xs semi">Total: {t.total}</div>
                </div>
                <div style={{ marginTop: 9 }}><Badge tone={tone(t.status) as any}>{t.status}</Badge></div>
              </div>
              {i < testHistory.length - 1 && <div className="divider" />}
            </div>
          ))}
        </div>
      </div>
    </Screen>
  );
}

/* ---------------------------- My channeling ---------------------------- */

export function ChannelingScreen() {
  const { back, appointments } = useClinic();
  const [filter, setFilter] = useState<'All' | 'Upcoming' | 'Refunded' | 'Completed'>('All');

  const groups = [
    { key: 'today', title: 'Today', items: appointments.filter((a) => a.status === 'upcoming' && a.date.toDateString() === new Date().toDateString()) },
    { key: 'upcoming', title: 'Upcoming', items: appointments.filter((a) => a.status === 'upcoming' && a.date.toDateString() !== new Date().toDateString()) },
    { key: 'completed', title: 'Completed', items: appointments.filter((a) => a.status === 'completed') },
    { key: 'refunded', title: 'Refunded', items: appointments.filter((a) => a.status === 'refunded') },
  ];

  const show = (key: string) =>
    filter === 'All' ||
    (filter === 'Upcoming' && (key === 'today' || key === 'upcoming')) ||
    (filter === 'Completed' && key === 'completed') ||
    (filter === 'Refunded' && key === 'refunded');

  return (
    <Screen>
      <div style={{ paddingBottom: 34, background: '#f5f6f8', minHeight: '100%' }}>
        <div style={{ padding: '0 16px' }}>
          <BackHeader title="My Channelings" onBack={back} />
        </div>

        <div style={{ padding: '0 16px 12px' }}>
          <div className="segmented">
            {(['All', 'Upcoming', 'Refunded', 'Completed'] as const).map((f) => (
              <button key={f} className={filter === f ? 'active' : ''} onClick={() => setFilter(f)}>{f}</button>
            ))}
          </div>
        </div>

        <div className="stack gap-22" style={{ padding: '4px 16px 0' }}>
          {groups.filter((g) => show(g.key)).map((g) => (
            <div key={g.key}>
              <div className="small bold" style={{ marginBottom: 9 }}>{g.title}</div>
              {g.items.length === 0 ? (
                <div className="xs muted" style={{ padding: '12px 0' }}>Nothing here yet.</div>
              ) : (
                <div className="stack gap-10">
                  {g.items.map((a) => (
                    <div key={a.id} style={{ background: '#fff', borderRadius: 12, padding: 14, border: '1px solid #eef1f5' }}>
                      <div className="small bold">{a.doctor.heading}</div>
                      <div className="xs" style={{ color: '#2563eb', marginTop: 3 }}>{a.doctor.subheading}</div>
                      <div className="xs muted" style={{ marginTop: 3 }}>{timeLabel(a.date)}</div>
                      <div className="xs semi" style={{ marginTop: 4 }}>Total: {money(a.totalFee)}</div>

                      {a.status === 'refunded' && (
                        <div className="row gap-6" style={{ marginTop: 9, padding: '7px 10px', background: '#fff7ed', borderRadius: 8 }}>
                          <span style={{ color: '#f59e0b' }}>{Icon.undo({ size: 13 })}</span>
                          <span className="xs" style={{ color: '#b45309' }}>Payment refunded to original method</span>
                        </div>
                      )}

                      {a.status === 'completed' && a.tests.length > 0 && (
                        <div style={{ marginTop: 9 }}>
                          <div className="xs bold" style={{ marginBottom: 3 }}>Tests Done</div>
                          {a.tests.map((t) => <div key={t} className="xs muted">• {t}</div>)}
                        </div>
                      )}

                      <div style={{ marginTop: 9 }}>
                        <Badge tone={a.status === 'completed' ? 'green' : a.status === 'refunded' ? 'orange' : 'blue'}>
                          {a.status === 'completed' ? 'Completed' : a.status === 'refunded' ? 'Refunded' : 'Upcoming'}
                        </Badge>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          ))}
        </div>
      </div>
    </Screen>
  );
}
