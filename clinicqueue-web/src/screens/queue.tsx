import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useClinic, totalFor } from '../data/store';
import { Activity, COLORS, money, ADMIN_FEE } from '../data/clinic';
import { Screen, FloatingNav } from '../components/shell';
import { Badge, BackHeader, Icon, PrimaryButton, Ring } from '../components/ui';
import { testImageFor } from './clinicFlow';

type Sim = 'waiting' | 'next' | 'ready' | 'inProgress' | 'completed';

const STAGE_META: Record<Sim, { label: string; num: string; hint: string }> = {
  waiting: { label: 'Your Queue Number', num: '', hint: 'Please stay nearby' },
  next: { label: 'You Are Next', num: '', hint: 'Head towards the room' },
  ready: { label: 'It\'s your turn!', num: '', hint: 'Show this QR code at the counter' },
  inProgress: { label: 'You Are Currently Being Served', num: '', hint: 'Almost done' },
  completed: { label: 'Completed', num: '', hint: 'Consultation completed' },
};

export function QueueScreen() {
  const {
    push, back, activities, setActivities, currentService, hasRejoinedDoctor, setHasRejoinedDoctor,
    visit, setVisit, setTestHistory,
  } = useClinic();

  const [sim, setSim] = useState<Sim>('waiting');
  const timers = useRef<number[]>([]);

  const activeActivity = activities.find(
    (a) => a.service === currentService && a.isSelected && a.queueStage !== 'cancel'
  );
  const requested = activities.filter(
    (a) => a.service === currentService && a.queueStage === 'unknown' && !a.isSelected && a.testName
  );
  const completed = activities.filter((a) => a.service === currentService && a.queueStage === 'completed');

  const queueNumber = useMemo(() => {
    const doc = activeActivity?.selectedDoctor;
    if (doc) {
      const n = parseInt(doc.activeQueueCount.split(' ')[0] || '0', 10);
      return (isNaN(n) ? 0 : n) + 1;
    }
    return 14;
  }, [activeActivity]);
  const nowServing = Math.max(queueNumber - 2, 1);

  /* queue simulation: waiting → next → ready → inProgress → completed (4s apart) */
  const runSimulation = (startAt: Sim = 'waiting') => {
    timers.current.forEach((t) => clearTimeout(t));
    timers.current = [];
    const order: Sim[] = ['next', 'ready', 'inProgress', 'completed'];
    const from = order.indexOf(startAt) + 1;
    setSim(startAt);
    order.slice(from === 0 ? 0 : from).forEach((stage, i) => {
      const id = window.setTimeout(() => {
        setSim(stage);
        setActivities((list) =>
          list.map((a) =>
            a.id === activeActivity?.id
              ? {
                  ...a,
                  queueStage: stage === 'next' ? 'next' : stage === 'ready' ? 'ready' : stage === 'inProgress' ? 'inProgress' : 'completed',
                  stage: stage === 'completed' ? 'completed' : 'inQueue',
                }
              : a
          )
        );
      }, (i + 1) * 4200);
      timers.current.push(id);
    });
  };

  useEffect(() => {
    if (!activeActivity) { setSim('completed'); return; }
    const stageMap: Record<string, Sim> = {
      wait: 'waiting', unknown: 'waiting', next: 'next', ready: 'ready',
      inProgress: 'inProgress', completed: 'completed', cancel: 'completed',
    };
    const start = stageMap[activeActivity.queueStage] ?? 'waiting';
    if (start === 'completed') { setSim('completed'); return; }
    runSimulation(start);
    return () => timers.current.forEach((t) => clearTimeout(t));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeActivity?.id]);

  const canRecheck = currentService === 'clinic' && !hasRejoinedDoctor &&
    activities.filter((a) => a.service === 'clinic').some((a) => a.selectedDoctor === undefined && a.queueStage === 'completed');

  const canLeave = sim === 'completed' && requested.length === 0 && !canRecheck;

  const startTest = (activity: Activity) => {
    if (sim !== 'completed') return;
    setActivities((list) =>
      list.map((a) =>
        a.service === currentService ? { ...a, isSelected: a.id === activity.id, queueStage: a.id === activity.id ? 'unknown' : a.queueStage } : a
      )
    );
    if (activity.labStep) {
      const step = activity.labStep;
      setTestHistory((h) => [
        {
          id: `th-${Date.now()}`, testName: step.name, doctor: visit?.selectedSymptom?.specialty ?? 'Clinic',
          date: new Date().toLocaleDateString('en-GB'), total: money((step.price ?? 0) + ADMIN_FEE), status: 'Pending',
        },
        ...h,
      ]);
    }
    push('appointmentStarter');
  };

  const reschedule = (activity: Activity) => {
    setActivities((list) => list.filter((a) => a.id !== activity.id));
    push('labList');
  };

  const recheckWithDoctor = () => {
    setHasRejoinedDoctor(true);
    setActivities((list) =>
      list.map((a) =>
        a.selectedDoctor
          ? { ...a, isSelected: true, queueStage: 'wait' as const }
          : { ...a, isSelected: false }
      )
    );
    setSim('waiting');
    runSimulation('waiting');
  };

  const leaveClinic = () => {
    setActivities((list) => list.filter((a) => a.service !== currentService));
    setHasRejoinedDoctor(false);
    setVisit((v) => (v ? { ...v, consultationFee: 0 } : v));
    push('services');
  };

  return (
    <Screen fab={<FloatingNav />}>
      <div style={{ paddingBottom: 40 }}>
        <div style={{ padding: '6px 16px 0' }}>
          <BackHeader title="Live Queue" onBack={back} />
        </div>

        {/* Queue hero */}
        {activeActivity && (
          <div className="queue-hero" style={{ backgroundImage: 'url(/assets/ui/hero-bg.png)', marginTop: 4 }}>
            {sim === 'waiting' && (
              <>
                <div className="label">Your Queue Number</div>
                <div className="big-number">{queueNumber}</div>
                <div className="label">Now Serving</div>
                <div className="black-box">{nowServing}</div>
                <div style={{ fontSize: 13.5, opacity: .92 }}>Estimated Wait Time · ~15 minutes</div>
              </>
            )}
            {sim === 'next' && (
              <>
                <div style={{ fontSize: 25, fontWeight: 800 }}>You Are Next</div>
                <div className="label">Your Queue Number</div>
                <div className="big-number">{queueNumber}</div>
                <div className="label">Now Serving</div>
                <div className="black-box">{nowServing}</div>
              </>
            )}
            {sim === 'ready' && (
              <>
                <div style={{ fontSize: 25, fontWeight: 800 }}>It's your turn!</div>
                <div className="big-number">{queueNumber}</div>
                <div style={{ fontSize: 15, fontWeight: 700, textAlign: 'center' }}>Show this QR code at the counter</div>
                <div className="qr-tile"><QRMini /></div>
              </>
            )}
            {sim === 'inProgress' && (
              <>
                <div style={{ fontSize: 21, fontWeight: 800, textAlign: 'center', maxWidth: 260 }}>
                  You Are Currently Being Served
                </div>
                <div className="big-number">{queueNumber}</div>
                <div style={{ width: 34, height: 34, borderRadius: '50%', border: '3.5px solid rgba(255,255,255,.3)', borderTopColor: '#fff', animation: 'spin .8s linear infinite' }} />
              </>
            )}
            {sim === 'completed' && (
              <>
                <div style={{
                  width: 96, height: 96, borderRadius: '50%', background: '#fff', color: COLORS.primary,
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                }}>{Icon.check({ size: 44 })}</div>
                <div style={{ fontSize: 16.5, fontWeight: 700, textAlign: 'center', maxWidth: 270, lineHeight: 1.4 }}>
                  Your consultation has been completed successfully.
                </div>
              </>
            )}
            <div className="row gap-6" style={{ marginTop: 2 }}>
              <span style={{ fontSize: 11, color: 'rgba(255,255,255,.85)' }}>
                Live simulation · advances automatically every few seconds
              </span>
            </div>
          </div>
        )}

        {!activeActivity && (
          <div style={{ padding: '40px 26px', textAlign: 'center' }}>
            <div className="h3">No active queue</div>
            <p className="small muted" style={{ marginTop: 8, lineHeight: 1.5 }}>
              Start a consultation or book a test to see your live queue here.
            </p>
            <div style={{ marginTop: 18 }}>
              <PrimaryButton onClick={() => push('services')}>Back to services</PrimaryButton>
            </div>
          </div>
        )}

        {sim === 'completed' && activeActivity && (
          <div style={{ padding: '22px 20px 0' }}>
            {completed.length > 0 && (
              <>
                <div className="h2" style={{ marginBottom: 12 }}>Completed Services</div>
                <div className="stack gap-12">
                  {completed.map((a) => <ActivityCard key={a.id} activity={a} />)}
                </div>
              </>
            )}

            {requested.length > 0 && (
              <>
                <div className="h2" style={{ margin: `${completed.length ? 24 : 0}px 0 12px` }}>Requested Services</div>
                <div className="stack gap-12">
                  {requested.map((a) => (
                    <ActivityCard
                      key={a.id}
                      activity={a}
                      active
                      onStart={() => startTest(a)}
                      onReschedule={() => reschedule(a)}
                    />
                  ))}
                </div>
              </>
            )}

            {requested.length === 0 && completed.length === 0 && (
              <div style={{ textAlign: 'center', padding: '4px 0 16px' }}>
                <p className="small muted" style={{ lineHeight: 1.6 }}>
                  No further services were ordered for this visit.
                </p>
              </div>
            )}

            <div className="stack gap-12" style={{ marginTop: 22 }}>
              {canRecheck && (
                <PrimaryButton onClick={recheckWithDoctor}>Recheck with Doctor</PrimaryButton>
              )}
              {canLeave && (
                <PrimaryButton variant="ghost" onClick={leaveClinic}>
                  {currentService === 'appointment' ? 'Done' : 'Leave Clinic'}
                </PrimaryButton>
              )}
            </div>
          </div>
        )}

        {sim !== 'completed' && activeActivity && (
          <div style={{ padding: '20px 20px 0' }}>
            <div className="card soft">
              <div className="between">
                <div>
                  <div className="small bold">{activeActivity.selectedDoctor?.heading ?? activeActivity.testName ?? 'Consultation'}</div>
                  <div className="xs muted" style={{ marginTop: 4 }}>
                    {activeActivity.selectedDoctor?.subheading ?? activeActivity.labStep?.location ?? activeActivity.imagingStep?.location ?? 'Main Clinic'}
                  </div>
                </div>
                <Ring value={sim === 'waiting' ? 20 : sim === 'next' ? 55 : sim === 'ready' ? 80 : 94}>
                  {queueNumber}
                </Ring>
              </div>
            </div>
            <div className="stack gap-8" style={{ marginTop: 16 }}>
              {(['waiting', 'next', 'ready', 'inProgress', 'completed'] as Sim[]).map((s) => {
                const idx = ['waiting', 'next', 'ready', 'inProgress', 'completed'].indexOf(s);
                const cur = ['waiting', 'next', 'ready', 'inProgress', 'completed'].indexOf(sim);
                return (
                  <div key={s} className="row gap-10" style={{ opacity: idx > cur ? .45 : 1 }}>
                    <span style={{
                      width: 22, height: 22, borderRadius: '50%', flex: '0 0 auto',
                      background: idx < cur ? '#22a06b' : idx === cur ? COLORS.primary : '#e2e8f0',
                      color: idx <= cur ? '#fff' : '#94a3b8',
                      display: 'flex', alignItems: 'center', justifyContent: 'center',
                    }}>{idx < cur ? Icon.check({ size: 12 }) : <span style={{ fontSize: 10, fontWeight: 800 }}>{idx + 1}</span>}</span>
                    <span className="small" style={{ fontWeight: idx === cur ? 800 : 600 }}>
                      {s === 'waiting' ? 'In queue' : s === 'next' ? 'You are next' : s === 'ready' ? 'Called to counter' : s === 'inProgress' ? 'In consultation' : 'Completed'}
                    </span>
                  </div>
                );
              })}
            </div>
          </div>
        )}
      </div>
    </Screen>
  );
}

function QRMini() {
  return (
    <svg width="104" height="104" viewBox="0 0 25 25" shapeRendering="crispEdges">
      <rect width="25" height="25" fill="#fff" />
      {Array.from({ length: 25 * 25 }).map((_, i) => {
        const x = i % 25;
        const y = Math.floor(i / 25);
        const on = ((x * 7 + y * 13 + ((x * y) % 5)) % 3 === 0) || (x < 3 && y < 3);
        return on ? <rect key={i} x={x} y={y} width="1" height="1" fill="#0b0f14" /> : null;
      })}
    </svg>
  );
}

function ActivityCard({
  activity, active, onStart, onReschedule,
}: { activity: Activity; active?: boolean; onStart?: () => void; onReschedule?: () => void }) {
  const step = activity.labStep ?? activity.imagingStep;
  return (
    <div className="test-card">
      <div className="row gap-14" style={{ alignItems: 'flex-start' }}>
        <img src={testImageFor(activity.testName ?? activity.selectedDoctor?.heading)} alt="" className="thumb" />
        <div className="grow">
          <div style={{ fontSize: 15, fontWeight: 800 }}>{activity.testName ?? activity.selectedDoctor?.heading ?? 'Service'}</div>
          {activity.selectedDoctor && (
            <div className="xs muted" style={{ marginTop: 3 }}>{activity.selectedDoctor.subheading}</div>
          )}
          {step && <div className="xs muted" style={{ marginTop: 3 }}>Location: {step.location}</div>}
          {step && <div className="xs muted">Approximate time: {step.estimatedWait}</div>}
        </div>
      </div>

      {step?.requirements && (
        <>
          <div className="divider" style={{ margin: '13px 0 11px' }} />
          <div className="xs bold" style={{ marginBottom: 4 }}>Requirements</div>
          {step.requirements.slice(0, 2).map((r) => (
            <div key={r} className="xs muted" style={{ lineHeight: 1.6 }}>• {r}</div>
          ))}
        </>
      )}

      <div className="row gap-10" style={{ marginTop: 14, justifyContent: 'center' }}>
        {active ? (
          <>
            <button onClick={onStart} style={{ padding: '9px 18px', borderRadius: 20, background: COLORS.themeTeal, color: '#fff', fontSize: 13, fontWeight: 700 }}>
              Start This Test
            </button>
            <button onClick={onReschedule} style={{ padding: '9px 18px', borderRadius: 20, border: `1.5px solid ${COLORS.themeTeal}`, color: COLORS.themeTeal, fontSize: 13, fontWeight: 700 }}>
              Schedule Later
            </button>
          </>
        ) : (
          <span className="price-pill" style={{ padding: '7px 20px' }}>
            Fee: {step?.price ? money(step.price) : activity.selectedDoctor?.price ?? 'Free'}
          </span>
        )}
      </div>
    </div>
  );
}
