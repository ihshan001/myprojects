import React from 'react';
import { ClinicProvider, useClinic, ScreenName } from './data/store';
import { LandingScreen, LoginScreen, PhoneScreen, OTPScreen } from './screens/entry';
import { ServicesScreen } from './screens/services';
import { PatientFormScreen, SymptomsScreen, AppointmentStarterScreen } from './screens/clinicFlow';
import {
  LabListScreen, LabDetailsScreen, ImagingListScreen, ImagingDetailsScreen, LabTrackerScreen,
} from './screens/labFlow';
import { PharmacyScreen, CartScreen, PharPaymentScreen } from './screens/pharmacy';
import { PaymentScreen, CashPayScreen, ProcessingScreen, PaymentStatusScreen } from './screens/payments';
import { QueueScreen } from './screens/queue';
import { FindDoctorScreen, DoctorStarterScreen } from './screens/doctors';
import {
  ProfileScreen, MyProfileScreen, NotificationsScreen, TransactionsScreen, TestHistoryScreen, ChannelingScreen,
} from './screens/profile';
import { MapScreen } from './screens/map';
import { Screen as ScreenWrap } from './components/shell';

/* ------------------------------- Router ------------------------------- */

function Router() {
  const { current } = useClinic();
  const p = (current.params ?? {}) as Record<string, any>;

  switch (current.name) {
    case 'landing': return <LandingScreen />;
    case 'login': return <LoginScreen />;
    case 'phone': return <PhoneScreen />;
    case 'otp': return <OTPScreen phone={p.phone ?? ''} />;
    case 'services': return <ServicesScreen />;
    case 'patientForm': return <PatientFormScreen />;
    case 'symptoms': return <SymptomsScreen />;
    case 'appointmentStarter': return <AppointmentStarterScreen />;
    case 'labList': return <LabListScreen />;
    case 'labDetails': return <LabDetailsScreen ids={p.ids ?? []} />;
    case 'imagingList': return <ImagingListScreen />;
    case 'imagingDetails': return <ImagingDetailsScreen ids={p.ids ?? []} />;
    case 'pharmacy': return <PharmacyScreen />;
    case 'cart': return <CartScreen lines={p.lines} prescription={p.prescription} />;
    case 'pharPayment': return <PharPaymentScreen total={p.total} />;
    case 'payment': return <PaymentScreen />;
    case 'cashPay': return <CashPayScreen />;
    case 'processing': return <ProcessingScreen />;
    case 'paymentStatus': return <PaymentStatusScreen />;
    case 'queue': return <QueueScreen />;
    case 'labTracker': return <LabTrackerScreen ids={p.ids ?? []} />;
    case 'findDoctor': return <FindDoctorScreen />;
    case 'doctorStarter': return <DoctorStarterScreen doctorId={p.doctorId} />;
    case 'profile': return <ProfileScreen />;
    case 'myProfile': return <MyProfileScreen />;
    case 'notifications': return <NotificationsScreen />;
    case 'transactions': return <TransactionsScreen />;
    case 'testHistory': return <TestHistoryScreen />;
    case 'channeling': return <ChannelingScreen />;
    case 'map': return <MapScreen />;
    default: return <ServicesScreen />;
  }
}

/* --------------------------- Explainer panel --------------------------- */

const JUMPS: { label: string; name: ScreenName; params?: Record<string, unknown>; note?: string }[] = [
  { label: 'Landing', name: 'landing' },
  { label: 'Services hub', name: 'services' },
  { label: 'Consultation', name: 'patientForm' },
  { label: 'Lab tests', name: 'labList' },
  { label: 'Imaging', name: 'imagingList' },
  { label: 'Pharmacy', name: 'pharmacy' },
  { label: 'Channeling', name: 'findDoctor' },
  { label: 'Live queue', name: 'queue' },
  { label: 'Indoor map', name: 'map' },
  { label: 'Profile', name: 'profile' },
  { label: 'Channeling history', name: 'channeling' },
];

function SidePanel() {
  const { push, current, session, activities } = useClinic();
  const activeQueue = activities.find((a) =>
    ['wait', 'next', 'ready', 'inProgress'].includes(a.queueStage) && a.service !== 'appointment'
  );

  return (
    <aside className="device-side">
      <div className="side-card">
        <div className="side-title">ClinicQueue · live preview</div>
        <h2 className="side-heading">Medisync patient app</h2>
        <p className="side-text">
          A working web replica of the <strong>SwiftUI iOS app</strong> in
          <code style={{ background: '#f1f5f9', padding: '1px 4px', borderRadius: 4, fontSize: 11.5 }}> clinic-queue-management-system-main</code>.
          Every screen from the Swift sources is navigable, and the queue engine really advances
          (wait → next → ready → in progress → completed).
        </p>
        <p className="side-text">
          Because the original is a native Xcode project, this preview is a faithful browser port —
          same flows, same data (25 doctors, 20 lab/imaging tests, 8 medicines) and the real artwork.
        </p>
        <div className="side-meta" style={{ marginTop: 12 }}>
          <span className="chip green">Queue simulation live</span>
          <span className="chip blue">{activities.length} activities</span>
          {session.isLoggedIn && <span className="chip amber">{session.loginMethod} session</span>}
        </div>
      </div>

      <div className="side-card">
        <div className="side-title">Jump to a screen</div>
        <div className="side-jump">
          {JUMPS.map((j) => (
            <button
              key={j.label}
              className={current.name === j.name ? 'active' : ''}
              onClick={() => push(j.name, j.params)}
            >
              {j.label}
            </button>
          ))}
        </div>
      </div>

      <div className="side-card">
        <div className="side-title">Try this end-to-end</div>
        <ol className="side-text" style={{ paddingLeft: 17, margin: 0, lineHeight: 1.7 }}>
          <li><strong>Continue</strong> → guest or phone sign-in</li>
          <li><strong>Consultation Today</strong> → name, age, gender → pick symptoms</li>
          <li>Pay (card: any 16 digits, CVV 123, expiry 28/12) → OTP → queue</li>
          <li>Watch the banner flip to <em>Next / Ready / Served</em>; then start requested lab tests</li>
          <li>Or book <strong>Doctor Channeling</strong>, browse the <strong>Map</strong>, and check Profile history</li>
        </ol>
      </div>

      {activeQueue && (
        <div className="side-card">
          <div className="side-title">Queue engine</div>
          <p className="side-text" style={{ marginBottom: 8 }}>
            {activeQueue.selectedDoctor?.heading ?? activeQueue.testName ?? 'Consultation'}
          </p>
          <span className="chip green">stage: {activeQueue.queueStage}</span>
        </div>
      )}
    </aside>
  );
}

/* --------------------------------- App --------------------------------- */

function Shell() {
  const { back, canGoBack } = useClinic();

  React.useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && canGoBack) back();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [back, canGoBack]);

  return (
    <div className="device-stage">
      <div className="device-column">
        <div className="device-frame">
          <div className="device-screen">
            <Router />
          </div>
        </div>
      </div>
      <SidePanel />
    </div>
  );
}

export default function App() {
  return (
    <ClinicProvider>
      <Shell />
    </ClinicProvider>
  );
}

export { ScreenWrap };
