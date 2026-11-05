import React, { useMemo, useState } from 'react';
import {
  useClinic, makeActivity, totalFor, activityFee, timeLabel, dayLabel,
} from '../data/store';
import {
  symptoms, symptoms as symptomList, doctorGroups, leastBusyDoctor, recommendedTestsForSymptom,
  ADMIN_FEE, COLORS, money, Doctor, ClinicStep, uid, priceOf,
} from '../data/clinic';
import { Screen, FloatingNav } from '../components/shell';
import {
  BackHeader, Field, Icon, PrimaryButton, Badge, SectionHeading, EmptyState,
} from '../components/ui';

/* --------------------------- Patient details -------------------------- */

export function PatientFormScreen() {
  const { push, back, setVisit, activities, setActivities, setCurrentService, setHasRejoinedDoctor } = useClinic();
  const [name, setName] = useState('');
  const [age, setAge] = useState('');
  const [gender, setGender] = useState<string | null>(null);
  const [open, setOpen] = useState(false);

  const nameValid = name.trim().length > 0 && !/\d/.test(name);
  const ageValid = Number(age) > 0 && Number(age) < 150;
  const valid = nameValid && ageValid && gender !== null;

  const proceed = () => {
    setCurrentService('clinic');
    setHasRejoinedDoctor(false);
    setVisit({
      patientName: name, age: Number(age), gender: gender ?? '',
      symptomKeys: [], consultationFee: 0, adminFee: ADMIN_FEE, paymentMethod: 'card',
    });
    const kept = activities.filter((a) => a.service !== 'clinic');
    const activity = makeActivity('clinic', {
      patientName: name, patientAge: Number(age), patientGender: gender ?? '', isSelected: true,
    });
    setActivities([...kept, activity]);
    push('symptoms');
  };

  const options = [['male', 'Male'], ['female', 'Female'], ['other', 'Other']];

  return (
    <Screen>
      <div style={{ padding: '6px 20px 24px' }}>
        <BackHeader title="Consultation" onBack={back} />
        <div style={{ marginTop: 12 }}>
          <div className="h2">Confirm Your Details</div>
          <p className="small muted" style={{ marginTop: 8, lineHeight: 1.5 }}>
            Please verify your information before joining the consultation queue.
          </p>
        </div>

        <div className="stack gap-14" style={{ marginTop: 24 }}>
          <Field placeholder="Patient Name" value={name} onChange={setName} />
          <Field placeholder="Age" value={age} onChange={(v) => setAge(v.replace(/\D/g, ''))} inputMode="numeric" />

          <div>
            <button
              onClick={() => setOpen((o) => !o)}
              className="row"
              style={{
                width: '100%', justifyContent: 'space-between', padding: '14px 16px',
                border: `1.5px solid ${open ? COLORS.primary : COLORS.border}`,
                borderRadius: 100, background: '#fff', fontSize: 15,
                color: gender ? COLORS.textDark : COLORS.placeholder,
              }}
            >
              <span>{options.find(([k]) => k === gender)?.[1] ?? 'Gender'}</span>
              <span style={{ transform: open ? 'rotate(180deg)' : 'none', transition: 'transform .2s', color: '#94a3b8' }}>
                {Icon.chevron({ size: 16 })}
              </span>
            </button>
            {open && (
              <div className="fade-in" style={{ marginTop: 8, border: '1px solid #e8ebed', borderRadius: 14, overflow: 'hidden' }}>
                {options.map(([key, label], i) => (
                  <button
                    key={key}
                    onClick={() => { setGender(key); setOpen(false); }}
                    style={{
                      display: 'block', width: '100%', textAlign: 'left', padding: '13px 16px',
                      fontSize: 14, color: COLORS.text, background: gender === key ? '#f2f9f7' : '#fff',
                      borderTop: i ? '1px solid #f1f4f7' : 'none',
                    }}
                  >
                    {label}
                  </button>
                ))}
              </div>
            )}
          </div>
        </div>

        <div style={{ marginTop: 26 }}>
          <PrimaryButton disabled={!valid} onClick={proceed}>Proceed to Queue</PrimaryButton>
        </div>
      </div>
    </Screen>
  );
}

/* ---------------------------- Symptom picker --------------------------- */

export function SymptomsScreen() {
  const {
    push, back, visit, setVisit, activities, setActivities, currentService,
  } = useClinic();
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const toggle = (key: string) => setSelected((s) => {
    const next = new Set(s);
    next.has(key) ? next.delete(key) : next.add(key);
    return next;
  });

  const proceed = () => {
    const keys = Array.from(selected);
    const first = symptomList.find((s) => s.key === keys[0]);

    // doctor assignment (least busy in the matching specialty)
    const doctors = doctorGroups.find((g) => g.specialty === first?.specialty)?.doctors ?? [];
    const chosen = leastBusyDoctor(doctors);

    setVisit((v) => (v ? { ...v, symptomKeys: keys, selectedSymptom: first } : v));

    setActivities((list) => {
      const next = list.map((a) =>
        a.service === 'clinic' && !a.testName
          ? { ...a, symptoms: keys, selectedDoctor: chosen, isSelected: true }
          : a
      );
      // append recommended diagnostic activities
      const extra = keys.flatMap((key) =>
        recommendedTestsForSymptom(key).map((test) =>
          makeActivity('clinic', {
            labStep: test.type === 'labTest' ? { ...test } : undefined,
            imagingStep: test.type === 'imaging' ? { ...test } : undefined,
            testName: test.name,
            patientName: visit?.patientName ?? '',
            symptoms: [key],
          })
        )
      );
      return [...next, ...extra];
    });

    push('appointmentStarter');
  };

  return (
    <Screen>
      <div style={{ padding: '6px 20px 0' }}>
        <BackHeader title="Symptoms" onBack={back} />
        <div style={{ textAlign: 'center', marginTop: 6 }}>
          <div className="h2">Select Your Symptoms</div>
          <p className="small muted" style={{ marginTop: 10, lineHeight: 1.55 }}>
            Choose the symptoms you're experiencing. We'll assign the most suitable available doctor
            and place you in the queue.
          </p>
        </div>

        <div className="stack gap-10" style={{ marginTop: 22, paddingBottom: 16 }}>
          {symptoms.map((s) => {
            const on = selected.has(s.key);
            return (
              <button
                key={s.id}
                onClick={() => toggle(s.key)}
                className="row between"
                style={{
                  padding: '14px 14px 14px 16px', borderRadius: 11,
                  background: on ? '#CDF9F6' : '#F4F7F7', transition: 'background .15s ease',
                }}
              >
                <div className="row gap-10">
                  <span style={{ fontSize: 15, fontWeight: 600, color: '#0b0f14' }}>{s.label}</span>
                  <span className="xs" style={{ color: on ? '#0f8c80' : '#9aa7b2', fontWeight: 700 }}>{s.specialty}</span>
                </div>
                {on ? (
                  <span style={{ width: 22, height: 22, borderRadius: '50%', background: COLORS.primary, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                    {Icon.check({ size: 13 })}
                  </span>
                ) : (
                  <span style={{ width: 18, height: 18, borderRadius: '50%', border: '1.5px solid #0b0f14' }} />
                )}
              </button>
            );
          })}
        </div>

        <div style={{ paddingBottom: 18 }}>
          <PrimaryButton disabled={selected.size === 0} onClick={proceed}>Proceed to Queue</PrimaryButton>
        </div>
      </div>
    </Screen>
  );
}

/* --------------------------- Queue / payment page --------------------- */

function QueueOptionButton({ number, sub, active }: { number: string; sub: string; active: boolean }) {
  return (
    <div style={{
      minWidth: 96, minHeight: 74, borderRadius: 11, display: 'flex', flexDirection: 'column',
      alignItems: 'center', justifyContent: 'center', gap: 5,
      background: active ? COLORS.primary : '#fff',
      border: `2px solid ${active ? COLORS.primaryDark : '#ECF5F5'}`,
      color: active ? '#fff' : '#0b0f14',
    }}>
      <span style={{ fontSize: 20, fontWeight: 800 }}>{number}</span>
      <span style={{ fontSize: 11.5, color: active ? 'rgba(255,255,255,.9)' : COLORS.text }}>{sub}</span>
    </div>
  );
}

export function AppointmentStarterScreen() {
  const { push, back, visit, activities, currentService, setPayment } = useClinic();
  const [method, setMethod] = useState<'card' | 'cash'>('card');

  const activeActivity = activities.find(
    (a) => a.service === currentService && a.isSelected && a.queueStage !== 'cancel'
  );
  const doctor = activeActivity?.selectedDoctor;
  const lab = activeActivity?.labStep;
  const imaging = activeActivity?.imagingStep;

  const fee = activityFee(activeActivity ?? { ...makeActivity('clinic') });
  const total = totalFor(fee, ADMIN_FEE);

  const nextQueue = doctor
    ? String(parseInt(doctor.activeQueueCount.split(' ')[0] || '0', 10) + 1).padStart(2, '0')
    : '12';

  const rows = [
    { label: doctor ? 'Consultation' : lab ? lab.name : imaging ? imaging.name : 'Service', value: money(fee) },
    { label: 'Admin Fee', value: money(ADMIN_FEE) },
    { label: 'Additional Discount', value: money(0) },
    { label: 'Total', value: money(total), total: true },
  ];

  return (
    <Screen>
      <div style={{ padding: '6px 20px 28px' }}>
        <BackHeader title="Your Clinic Queue" onBack={back} />

        {doctor && (
          <div className="card" style={{ marginTop: 8 }}>
            <div className="doctor-card">
              <img src={doctor.image} alt="" className="avatar lg" />
              <div className="grow">
                <div className="between" style={{ alignItems: 'flex-start' }}>
                  <div>
                    <div style={{ fontSize: 16, fontWeight: 800 }}>{doctor.heading}</div>
                    <div className="small" style={{ color: COLORS.placeholder, marginTop: 2 }}>{doctor.subheading}</div>
                  </div>
                  <span className="price-pill">{doctor.price}</span>
                </div>
                <div style={{ marginTop: 8, color: COLORS.primary, fontSize: 13, fontWeight: 700 }}>
                  {doctor.activeQueueCount}
                </div>
                <div className="stack gap-4" style={{ marginTop: 6 }}>
                  <div className="row gap-4 small muted">
                    <span>Estimated wait:</span><span style={{ color: COLORS.textDark, fontWeight: 600 }}>{doctor.detail1.value}</span>
                  </div>
                  <div className="row gap-4 small muted">
                    <span>Location:</span><span style={{ color: COLORS.textDark, fontWeight: 600 }}>{doctor.detail2.value}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}

        {(lab || imaging) && (
          <div className="test-card" style={{ marginTop: 14 }}>
            <div className="row gap-14" style={{ alignItems: 'flex-start' }}>
              <img src={testImageFor(lab?.name ?? imaging?.name)} alt="" className="thumb" />
              <div className="grow">
                <div style={{ fontWeight: 800, fontSize: 15 }}>{lab?.name ?? imaging?.name}</div>
                <div className="small muted" style={{ marginTop: 4 }}>
                  Location: {lab?.location ?? imaging?.location}
                </div>
                <div className="row gap-6" style={{ marginTop: 8 }}>
                  <Badge tone="green">Available Now</Badge>
                  <span className="xs muted">{lab?.estimatedWait ?? imaging?.estimatedWait}</span>
                </div>
              </div>
            </div>
            <div className="divider" style={{ margin: '14px 0 12px' }} />
            <div className="between">
              <div>
                <div className="xs bold" style={{ marginBottom: 4 }}>Requirements</div>
                {(lab?.requirements ?? imaging?.requirements ?? []).map((r) => (
                  <div key={r} className="xs muted" style={{ lineHeight: 1.6 }}>• {r}</div>
                ))}
              </div>
              <div style={{ textAlign: 'right' }}>
                <div className="xs bold">Approximate Time</div>
                <div className="xs muted" style={{ marginTop: 4 }}>{lab?.estimatedWait ?? imaging?.estimatedWait}</div>
              </div>
            </div>
          </div>
        )}

        <div style={{ marginTop: 24 }}>
          <div className="small bold" style={{ textAlign: 'center', marginBottom: 12 }}>Available Queues</div>
          <div className="row" style={{ justifyContent: 'center' }}>
            <QueueOptionButton number={nextQueue} sub={doctor ? '~15 min' : lab?.estimatedWait ?? imaging?.estimatedWait ?? '~10 min'} active />
          </div>
        </div>

        <div style={{ marginTop: 24 }}>
          <div className="h2" style={{ marginBottom: 14 }}>Payment Details</div>
          {rows.map((r) => (
            <div key={r.label} className="between" style={{ marginBottom: 11 }}>
              <span style={{ fontSize: 15, color: r.total ? '#0b0f14' : COLORS.lable, fontWeight: r.total ? 800 : 500 }}>{r.label}</span>
              <span style={{ fontSize: 16, fontWeight: 800, color: '#0b0f14' }}>{r.value}</span>
            </div>
          ))}
        </div>

        <div style={{ marginTop: 24 }}>
          <div className="h2" style={{ marginBottom: 12 }}>Payment Option</div>
          <div className="stack gap-10">
            <PayOption label="Card Payment" icon="/assets/pay/card.png" selected />
            <PayOption label="Cash Payment" icon="/assets/pay/cash.png" selected={false} />
          </div>
        </div>

        <div style={{ marginTop: 26 }}>
          <PrimaryButton
            onClick={() => {
              setPayment({
                title: 'Consultation',
                label: doctor ? 'Consultation' : lab ? lab.name : imaging ? imaging.name : 'Service',
                amount: fee,
                adminFee: ADMIN_FEE,
                method,
                onSuccessScreen: 'queue',
              });
              push(method === 'card' ? 'payment' : 'cashPay');
            }}
          >
            Book Appointment
          </PrimaryButton>
        </div>
        <div className="row" style={{ marginTop: 16 }}>
          <FloatingNav />
        </div>
      </div>
    </Screen>
  );
}

export function PayOption({ label, icon, selected }: { label: string; icon: string; selected: boolean }) {
  return (
    <div className={`pay-option ${selected ? 'selected' : ''}`}>
      <img src={icon} alt="" />
      <span style={{ fontSize: 15, fontWeight: selected ? 700 : 500 }}>{label}</span>
      <span className="spacer" />
      <span className="radio">{selected && <i />}</span>
    </div>
  );
}

export const testImageFor = (name?: string): string => {
  const idx = [
    'Complete Blood Count (CBC)', 'Lipid Panel', 'HbA1c (Glycated Hemoglobin)', 'Urinalysis (Full Report)',
    'Thyroid Function Test (TFT)', 'Liver Function Test (LFT)', 'Kidney Function Test (KFT)',
    'Vitamin D (25-Hydroxy)', 'Electrolyte Panel', 'Prostate-Specific Antigen (PSA)',
    'Chest X-Ray (PA & Lateral)', 'CT Scan - Abdomen & Pelvis', 'MRI Brain (Non-Contrast)',
    'Ultrasound - Whole Abdomen', 'Digital Mammography', 'DXA Bone Density Scan',
    'CT Coronary Angiography', 'X-Ray - Knee (Left/Right)', 'MRI Spine (Lumbar)', 'CT Head (Emergency/Trauma)',
  ].findIndex((n) => n === name);
  return idx >= 0 ? `/assets/tests/test${idx + 1}.jpg` : '/assets/tests/test1.jpg';
};
