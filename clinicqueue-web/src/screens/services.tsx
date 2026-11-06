import React from 'react';
import { useClinic } from '../data/store';
import { Screen, FloatingNav } from '../components/shell';
import { Icon, Ring } from '../components/ui';

interface ServiceTile {
  icon: React.ReactNode;
  title: string;
  subtitle: string;
  bg: string;
  screen: 'patientForm' | 'labList' | 'imagingList' | 'pharmacy';
}

export function ServicesScreen() {
  const { push, activities, setCurrentService, visit } = useClinic();

  const queued = activities.find((a) =>
    ['wait', 'next', 'ready', 'inProgress'].includes(a.queueStage) && a.service !== 'appointment'
  );
  const pendingTest = activities.find((a) => a.testName && a.queueStage === 'unknown' && a.service === 'clinic');
  const bannerActivity = queued ?? pendingTest;

  const services: ServiceTile[] = [
    {
      icon: Icon.stethoscope({ size: 21 }), title: 'Consultation Today',
      subtitle: 'Complete clinic visit and treatment process.',
      bg: '#F7F6FF', screen: 'patientForm',
    },
    {
      icon: Icon.flask({ size: 21 }), title: 'Laboratory',
      subtitle: 'Blood tests and diagnostic services.',
      bg: '#E8F3E8', screen: 'labList',
    },
    {
      icon: Icon.scan({ size: 21 }), title: 'Imaging',
      subtitle: 'X-ray and medical imaging services.',
      bg: '#FFF9E8', screen: 'imagingList',
    },
    {
      icon: Icon.pill({ size: 21 }), title: 'Pharmacy',
      subtitle: 'Collect prescribed medicines and supplies.',
      bg: '#F0F2F6', screen: 'pharmacy',
    },
  ];

  const queueNumber = bannerActivity?.queueNumber ?? 12;

  return (
    <Screen fab={<FloatingNav />}>
      <div style={{ padding: '4px 20px 34px' }}>
        {/* header */}
        <div className="between" style={{ alignItems: 'flex-start', marginBottom: 18 }}>
          <div>
            <div style={{ fontSize: 25, fontWeight: 800, letterSpacing: '-.03em', lineHeight: 1.15 }}>
              Find your desire
            </div>
            <div style={{ fontSize: 25, fontWeight: 800, letterSpacing: '-.03em', lineHeight: 1.15 }}>
              health solution
            </div>
          </div>
          <div className="row gap-14" style={{ gap: 14, marginTop: 4 }}>
            <button onClick={() => push('notifications')} style={{ position: 'relative', color: '#0b0f14' }}>
              {Icon.bell({ size: 21 })}
              <span style={{
                position: 'absolute', top: -2, right: -2, width: 7, height: 7, borderRadius: '50%',
                background: '#ef4444', border: '1.5px solid #fff',
              }} />
            </button>
            <button
              onClick={() => push('profile')}
              style={{
                width: 36, height: 36, borderRadius: '50%', background: 'rgba(100,116,139,.24)',
                fontSize: 11, fontWeight: 800, color: '#0b0f14',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
              }}
            >
              KL
            </button>
          </div>
        </div>

        {/* active queue banner */}
        {bannerActivity && (
          <button
            className="active-banner fade-in"
            style={{ marginBottom: 20 }}
            onClick={() => {
              setCurrentService(bannerActivity.service);
              push('queue');
            }}
          >
            <Ring value={queued ? Math.min(queueNumber * 10, 100) : 0}>
              {queued ? queueNumber : '--'}
            </Ring>
            <div className="grow">
              <div style={{ fontSize: 16, fontWeight: 800, marginBottom: 3 }}>
                {queued ? `Your Queue: ${queued.selectedDoctor?.heading ?? 'Doctor'}` : 'Pending Tests'}
              </div>
              <div className="small muted" style={{ lineHeight: 1.4 }}>
                {queued
                  ? `Patient: ${queued.patientName ?? visit?.patientName ?? '--'}, Queue Number: ${queueNumber}`
                  : 'You have pending tests — tap to continue'}
              </div>
            </div>
            <span style={{ color: '#479484', fontWeight: 800, fontSize: 13 }}>View</span>
          </button>
        )}

        {/* services grid */}
        <div className="service-grid">
          {services.map((s) => (
            <button
              key={s.title}
              className="service-card"
              style={{ background: s.bg }}
              onClick={() => {
                if (s.screen === 'patientForm' || s.screen === 'labList' || s.screen === 'imagingList') {
                  setCurrentService(s.screen === 'patientForm' ? 'clinic' : s.screen === 'labList' ? 'lab' : 'imaging');
                }
                push(s.screen);
              }}
            >
              <div className="icon-box">{s.icon}</div>
              <div>
                <h4>{s.title}</h4>
                <p style={{ marginTop: 5 }}>{s.subtitle}</p>
              </div>
            </button>
          ))}
        </div>

        {/* channeling */}
        <div style={{ marginTop: 28 }}>
          <div className="h3" style={{ fontSize: 19 }}>Doctor Channeling</div>
          <p className="small muted" style={{ marginTop: 6, lineHeight: 1.5 }}>
            Reserve your appointment with a doctor at your preferred date and time.
          </p>
        </div>

        <button
          className="service-card"
          style={{ background: '#F7F6FF', marginTop: 14, minHeight: 132, alignItems: 'center', textAlign: 'center' }}
          onClick={() => push('findDoctor')}
        >
          <div className="icon-box">{Icon.calendar({ size: 21 })}</div>
          <div>
            <h4 style={{ textAlign: 'center' }}>Doctor Appointment</h4>
            <p style={{ marginTop: 5, textAlign: 'center' }}>Book and manage doctor appointments.</p>
          </div>
        </button>
      </div>
    </Screen>
  );
}
