import React, { useMemo, useState } from 'react';
import { useClinic, makeActivity, totalFor, dayLabel, timeLabel } from '../data/store';
import {
  doctorCategories, doctorGroups, allDoctors, Doctor, COLORS, money, ADMIN_FEE, priceOf,
  recommendedTestsForSpecialty, DoctorAvailability,
} from '../data/clinic';
import { Screen, FloatingNav } from '../components/shell';
import { BackHeader, Badge, Icon, PrimaryButton, SearchField, SectionHeading } from '../components/ui';
import { PayOption } from './clinicFlow';

/* ------------------------------ Find doctor ----------------------------- */

export function DoctorCard({ doctor, onPrice, compact }: { doctor: Doctor; onPrice?: () => void; compact?: boolean }) {
  return (
    <div className="card" style={{ padding: 15 }}>
      <div className="doctor-card">
        <img src={doctor.image} alt="" className="avatar lg" />
        <div className="grow" style={{ minWidth: 0 }}>
          <div style={{ fontSize: 15.5, fontWeight: 800 }}>{doctor.heading}</div>
          <div className="small" style={{ color: COLORS.placeholder, marginTop: 2 }}>{doctor.subheading}</div>
          <div className="small" style={{ color: COLORS.primary, fontWeight: 700, marginTop: 7 }}>{doctor.activeQueueCount}</div>
          <div className="row gap-4 xs muted" style={{ marginTop: 5, flexWrap: 'wrap' }}>
            <span>{doctor.detail1.label}</span>
            <span style={{ color: COLORS.textDark, fontWeight: 600 }}>{doctor.detail1.value}</span>
            <span style={{ margin: '0 3px' }}>·</span>
            <span>{doctor.detail2.label}</span>
            <span style={{ color: COLORS.textDark, fontWeight: 600 }}>{doctor.detail2.value}</span>
          </div>
          {!compact && (
            <button
              onClick={onPrice}
              className="price-pill"
              style={{ marginTop: 9, border: 'none', cursor: 'pointer' }}
            >
              {doctor.price}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

function CategoryGrid({
  items, selected, onToggle, columns = 4,
}: { items: { id: string; title: string; icon: string }[]; selected: Set<string>; onToggle: (t: string) => void; columns?: number }) {
  return (
    <div className="cat-grid" style={{ gridTemplateColumns: `repeat(${columns}, 1fr)` }}>
      {items.map((c) => (
        <button
          key={c.id}
          className={`cat-box ${selected.has(c.title) ? 'selected' : ''}`}
          onClick={() => onToggle(c.title)}
        >
          <img src={c.icon} alt="" />
          <span>{c.title}</span>
        </button>
      ))}
    </div>
  );
}

export function FindDoctorScreen() {
  const { push, back, setCurrentService, setActivities, setVisit } = useClinic();
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [catModal, setCatModal] = useState(false);
  const [catSearch, setCatSearch] = useState('');
  const [availDoctor, setAvailDoctor] = useState<Doctor | null>(null);

  React.useEffect(() => setCurrentService('appointment'), [setCurrentService]);

  const defaultGrid = useMemo(() => {
    const sel = doctorCategories.filter((c) => selected.has(c.title));
    const unsel = doctorCategories.filter((c) => !selected.has(c.title));
    return [...sel, ...unsel].slice(0, 8);
  }, [selected]);

  const filtered = useMemo(() => {
    let list = selected.size ? doctorGroups.filter((g) => selected.has(g.specialty)).flatMap((g) => g.doctors) : allDoctors;
    if (search) list = list.filter((d) => d.heading.toLowerCase().includes(search.toLowerCase()));
    return list;
  }, [selected, search]);

  const toggle = (t: string) => setSelected((s) => {
    const n = new Set(s); n.has(t) ? n.delete(t) : n.add(t); return n;
  });

  const pickDate = (doctor: Doctor, date: DoctorAvailability) => {
    // mirrors FindDoctorView.assignDoctorToAppointment
    setActivities((list) => {
      const cleaned = list.filter((a) => a.service !== 'appointment');
      const main = makeActivity('appointment', {
        selectedDoctor: doctor, appointmentDate: date.date, isSelected: true,
        stage: 'planning', patientName: 'John Doe', patientAge: 32, patientGender: 'Male',
      });
      const tests = recommendedTestsForSpecialty(doctor.subheading).map((t) =>
        makeActivity('appointment', {
          selectedDoctor: doctor, appointmentDate: date.date, isSelected: false,
          labStep: t.type === 'labTest' ? { ...t } : undefined,
          imagingStep: t.type === 'imaging' ? { ...t } : undefined,
          testName: t.name,
        })
      );
      return [...cleaned, main, ...tests];
    });
    setVisit((v) => v ?? {
      patientName: 'John Doe', age: 32, gender: 'Male', symptomKeys: [],
      consultationFee: priceOf(doctor.price), adminFee: ADMIN_FEE, paymentMethod: 'card',
    });
    setAvailDoctor(null);
    push('doctorStarter', { doctorId: doctor.id });
  };

  const modalCats = doctorCategories.filter((c) => !catSearch || c.title.toLowerCase().includes(catSearch.toLowerCase()));

  return (
    <Screen>
      <div style={{ padding: '6px 18px 30px' }}>
        <BackHeader title="Doctor Channeling" onBack={back} />

        <div style={{ marginTop: 10 }}>
          <SearchField value={search} onChange={setSearch} placeholder="Find a Doctor" />
        </div>

        <div className="between" style={{ marginTop: 22 }}>
          <div className="h3">Category</div>
          <button className="small muted" onClick={() => setCatModal(true)}>See all</button>
        </div>

        <div style={{ marginTop: 12 }}>
          <CategoryGrid items={defaultGrid} selected={selected} onToggle={toggle} />
        </div>

        <div className="between" style={{ marginTop: 24, marginBottom: 12 }}>
          <div className="h3">Available Doctors</div>
          <span className="small muted">{filtered.length}</span>
        </div>

        <div className="stack gap-12">
          {filtered.map((d) => (
            <DoctorCard key={d.id} doctor={d} onPrice={() => setAvailDoctor(d)} />
          ))}
          {filtered.length === 0 && (
            <div className="small muted" style={{ textAlign: 'center', padding: 24 }}>No doctors match those filters.</div>
          )}
        </div>
      </div>

      {catModal && (
        <div className="overlay" onClick={() => setCatModal(false)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()}>
            <div className="h2" style={{ textAlign: 'center', marginBottom: 14 }}>Select Categories</div>
            <SearchField value={catSearch} onChange={setCatSearch} placeholder="Search Categories" />
            <div style={{ marginTop: 16, overflowY: 'auto' }}>
              <CategoryGrid items={modalCats} selected={selected} onToggle={toggle} />
            </div>
            <div style={{ marginTop: 16 }}>
              <PrimaryButton onClick={() => setCatModal(false)}>Done</PrimaryButton>
            </div>
          </div>
        </div>
      )}

      {availDoctor && (
        <div className="overlay" onClick={() => setAvailDoctor(null)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()}>
            <div className="h2" style={{ marginBottom: 4 }}>Available Dates</div>
            <p className="xs muted" style={{ marginBottom: 12 }}>{availDoctor.heading} · {availDoctor.subheading}</p>
            <div style={{ overflowY: 'auto' }}>
              <div className="h3" style={{ fontSize: 13, marginBottom: 8 }}>This week</div>
              <div className="stack gap-8">
                {availDoctor.availableDates.map((a) => (
                  <button
                    key={a.id}
                    onClick={() => pickDate(availDoctor, a)}
                    className="between"
                    style={{ padding: '12px 14px', borderRadius: 11, border: '1.5px solid #E8EBED', background: '#fff', width: '100%' }}
                  >
                    <span className="row gap-8 small semi">
                      {Icon.calendar({ size: 14 })}
                      {dayLabel(a.date)}
                    </span>
                    <span className="small muted">{a.timeRange}</span>
                    <span style={{ width: 18, height: 18, borderRadius: '50%', border: '2px solid #cbd5e1' }} />
                  </button>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}
    </Screen>
  );
}

/* --------------------------- Appointment starter ------------------------ */

export function DoctorStarterScreen({ doctorId }: { doctorId: string }) {
  const { push, back, setPayment, setCurrentAppointmentId, appointments, setAppointments, setActivities, activities } = useClinic();
  const doctor = allDoctors.find((d) => d.id === doctorId)!;
  const [queue, setQueue] = useState<string | null>(null);
  const [method, setMethod] = useState<'card' | 'cash'>('card');

  const currentQueue = parseInt(doctor.activeQueueCount.split(' ')[0] || '0', 10);
  const start = Math.min(currentQueue + 1, 20);
  const queues = Array.from({ length: Math.max(20 - start + 1, 1) }, (_, i) => String(start + i).padStart(2, '0'));
  const selectedQueue = queue ?? queues[0];

  const fee = priceOf(doctor.price);
  const total = totalFor(fee, ADMIN_FEE);

  const book = () => {
    const id = `appt-${Date.now()}`;
    const main = activities.find((a) => a.service === 'appointment' && a.isSelected);
    setAppointments((list) => [
      {
        id,
        date: main?.appointmentDate ?? new Date(),
        patientName: 'John Doe',
        age: 32,
        gender: 'Male',
        doctor,
        totalFee: total,
        status: 'upcoming',
        tests: activities.filter((a) => a.testName).map((a) => a.testName!),
      },
      ...list,
    ]);
    setCurrentAppointmentId(id);
    setPayment({
      title: 'Appointment', label: 'Consultation', amount: fee, adminFee: ADMIN_FEE,
      method, onSuccessScreen: 'channeling',
    });
    push(method === 'card' ? 'payment' : 'cashPay');
  };

  return (
    <Screen>
      <div style={{ padding: '6px 18px 30px' }}>
        <BackHeader title="Your Appointment" onBack={back} />

        <div style={{ marginTop: 8 }}>
          <DoctorCard doctor={doctor} compact />
        </div>

        <div style={{ marginTop: 22 }}>
          <div className="small bold" style={{ textAlign: 'center', marginBottom: 12 }}>Available Queues</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 9 }}>
            {queues.map((q) => {
              const on = q === selectedQueue;
              return (
                <button
                  key={q}
                  onClick={() => setQueue(q)}
                  style={{
                    padding: '12px 4px', borderRadius: 10,
                    background: on ? COLORS.primary : '#fff',
                    border: `2px solid ${on ? COLORS.primaryDark : '#ECF5F5'}`,
                    color: on ? '#fff' : '#0b0f14',
                    display: 'flex', flexDirection: 'column', gap: 3, alignItems: 'center',
                  }}
                >
                  <span style={{ fontSize: 18, fontWeight: 800 }}>{q}</span>
                  <span style={{ fontSize: 10.5, color: on ? 'rgba(255,255,255,.9)' : COLORS.text }}>~15 min</span>
                </button>
              );
            })}
          </div>
        </div>

        <div style={{ marginTop: 22 }}>
          <div className="h2" style={{ marginBottom: 14 }}>Payment Details</div>
          {[
            { label: 'Consultation', value: money(fee) },
            { label: 'Admin Fee', value: money(ADMIN_FEE) },
            { label: 'Additional Discount', value: money(0) },
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
            <button style={{ width: '100%' }} onClick={() => setMethod('card')}>
              <PayOption label="Card Payment" icon="/assets/pay/card.png" selected={method === 'card'} />
            </button>
            <button style={{ width: '100%' }} onClick={() => setMethod('cash')}>
              <PayOption label="Cash Payment" icon="/assets/pay/cash.png" selected={method === 'cash'} />
            </button>
          </div>
        </div>

        <div style={{ marginTop: 24 }}>
          <PrimaryButton onClick={book}>Book Appointment</PrimaryButton>
        </div>

        <div className="card soft" style={{ marginTop: 16 }}>
          <div className="small" style={{ lineHeight: 1.55 }}>
            <span className="bold">Queue {selectedQueue}</span> · {doctor.detail2.value} · {doctor.heading}
            <div className="xs muted" style={{ marginTop: 5 }}>
              Reserved for {new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'short' })}, 17:30
            </div>
          </div>
        </div>
      </div>
    </Screen>
  );
}
