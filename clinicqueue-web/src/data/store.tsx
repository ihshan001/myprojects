import React, { createContext, useCallback, useContext, useMemo, useState } from 'react';
import {
  Activity, ClinicStep, Doctor, ServiceType, QueueStage, uid, ADMIN_FEE, DISCOUNT,
  AppointmentRecord, seedAppointments, TransactionRecord, seedTransactions,
  TestHistoryRecord, seedTestHistory, priceOf, DoctorAvailability,
} from './clinic';

export type ScreenName =
  | 'landing' | 'login' | 'phone' | 'otp' | 'services'
  | 'patientForm' | 'symptoms' | 'clinicQueue'
  | 'labList' | 'labDetails' | 'imagingList' | 'imagingDetails'
  | 'pharmacy' | 'cart' | 'pharPayment'
  | 'payment' | 'cashPay' | 'processing' | 'paymentStatus'
  | 'queue' | 'labTracker' | 'labReady' | 'labDone'
  | 'appointmentStarter'
  | 'findDoctor' | 'doctorStarter' | 'doctorList' | 'doctorAppointment'
  | 'profile' | 'myProfile' | 'notifications' | 'transactions' | 'testHistory'
  | 'channeling' | 'map' | 'settings';

export interface Route { name: ScreenName; params?: Record<string, unknown> }

export interface SessionState {
  isLoggedIn: boolean;
  name: string;
  age?: number;
  gender: string;
  phoneNumber: string;
  loginMethod: 'guest' | 'phone' | 'apple' | 'google';
}

export interface VisitState {
  patientName: string;
  age: number;
  gender: string;
  symptomKeys: string[];
  selectedSymptom?: { key: string; label: string; specialty: string };
  consultationFee: number;
  adminFee: number;
  paymentMethod: string;
}

interface Ctx {
  stack: Route[];
  current: Route;
  push: (name: ScreenName, params?: Record<string, unknown>) => void;
  back: () => void;
  resetTo: (name: ScreenName, params?: Record<string, unknown>) => void;
  canGoBack: boolean;

  session: SessionState;
  setSession: React.Dispatch<React.SetStateAction<SessionState>>;
  startGuestSession: () => void;
  startPhoneSession: (phone: string) => void;
  startSSOSession: (provider: 'apple' | 'google') => void;

  currentService: ServiceType;
  setCurrentService: (s: ServiceType) => void;
  activities: Activity[];
  setActivities: React.Dispatch<React.SetStateAction<Activity[]>>;
  addActivity: (service: ServiceType) => Activity;
  resetFlow: () => void;
  hasRejoinedDoctor: boolean;
  setHasRejoinedDoctor: (v: boolean) => void;

  visit: VisitState | null;
  setVisit: React.Dispatch<React.SetStateAction<VisitState | null>>;

  appointments: AppointmentRecord[];
  setAppointments: React.Dispatch<React.SetStateAction<AppointmentRecord[]>>;
  transactions: TransactionRecord[];
  setTransactions: React.Dispatch<React.SetStateAction<TransactionRecord[]>>;
  testHistory: TestHistoryRecord[];
  setTestHistory: React.Dispatch<React.SetStateAction<TestHistoryRecord[]>>;
  currentAppointmentId: string | null;
  setCurrentAppointmentId: (id: string | null) => void;

  payment: {
    title: string;
    amount: number;
    adminFee: number;
    label: string;
    method: 'card' | 'cash';
    onSuccessScreen: ScreenName;
    onSuccessParams?: Record<string, unknown>;
    extraRows?: { label: string; value: string }[];
  } | null;
  setPayment: React.Dispatch<React.SetStateAction<Ctx['payment']>>;
}

const ClinicContext = createContext<Ctx | null>(null);

export const useClinic = () => {
  const ctx = useContext(ClinicContext);
  if (!ctx) throw new Error('useClinic must be used inside ClinicProvider');
  return ctx;
};

export const makeActivity = (service: ServiceType, partial: Partial<Activity> = {}): Activity => ({
  id: uid(),
  service,
  stage: 'unknown',
  isSelected: false,
  queueStage: 'unknown',
  symptoms: [],
  ...partial,
});

export function ClinicProvider({ children }: { children: React.ReactNode }) {
  const [stack, setStack] = useState<Route[]>([{ name: 'landing' }]);
  const [session, setSession] = useState<SessionState>({
    isLoggedIn: false, name: 'John Doe', gender: '', phoneNumber: '', loginMethod: 'guest',
  });
  const [currentService, setCurrentService] = useState<ServiceType>('unknown');
  const [activities, setActivities] = useState<Activity[]>([]);
  const [hasRejoinedDoctor, setHasRejoinedDoctor] = useState(false);
  const [visit, setVisit] = useState<VisitState | null>(null);
  const [appointments, setAppointments] = useState<AppointmentRecord[]>(seedAppointments);
  const [transactions, setTransactions] = useState<TransactionRecord[]>(seedTransactions);
  const [testHistory, setTestHistory] = useState<TestHistoryRecord[]>(seedTestHistory);
  const [currentAppointmentId, setCurrentAppointmentId] = useState<string | null>(null);
  const [payment, setPayment] = useState<Ctx['payment']>(null);

  const push = useCallback((name: ScreenName, params?: Record<string, unknown>) => {
    setStack((s) => [...s, { name, params }]);
    window.scrollTo({ top: 0 });
  }, []);

  const back = useCallback(() => {
    setStack((s) => (s.length > 1 ? s.slice(0, -1) : s));
  }, []);

  const resetTo = useCallback((name: ScreenName, params?: Record<string, unknown>) => {
    setStack([{ name, params }]);
    window.scrollTo({ top: 0 });
  }, []);

  const addActivity = useCallback((service: ServiceType) => {
    const activity = makeActivity(service);
    setActivities((a) => [...a, activity]);
    setCurrentService(service);
    return activity;
  }, []);

  const resetFlow = useCallback(() => {
    setCurrentService('unknown');
    setActivities([]);
    setHasRejoinedDoctor(false);
    setCurrentAppointmentId(null);
  }, []);

  const startGuestSession = useCallback(() => {
    setSession((s) => ({ ...s, isLoggedIn: true, loginMethod: 'guest' }));
  }, []);

  const startPhoneSession = useCallback((phone: string) => {
    setSession((s) => ({ ...s, isLoggedIn: true, phoneNumber: phone, loginMethod: 'phone' }));
  }, []);

  const startSSOSession = useCallback((provider: 'apple' | 'google') => {
    setSession((s) => ({ ...s, isLoggedIn: true, loginMethod: provider }));
  }, []);

  const value: Ctx = useMemo(() => ({
    stack, current: stack[stack.length - 1], push, back, resetTo, canGoBack: stack.length > 1,
    session, setSession, startGuestSession, startPhoneSession, startSSOSession,
    currentService, setCurrentService, activities, setActivities, addActivity, resetFlow,
    hasRejoinedDoctor, setHasRejoinedDoctor,
    visit, setVisit,
    appointments, setAppointments, transactions, setTransactions,
    testHistory, setTestHistory, currentAppointmentId, setCurrentAppointmentId,
    payment, setPayment,
  }), [
    stack, push, back, resetTo, session, startGuestSession, startPhoneSession, startSSOSession,
    currentService, activities, addActivity, resetFlow, hasRejoinedDoctor, visit,
    appointments, transactions, testHistory, currentAppointmentId, payment,
  ]);

  return <ClinicContext.Provider value={value}>{children}</ClinicContext.Provider>;
}

/* ------------------------- shared helpers ------------------------- */

export const totalFor = (fee: number, admin = ADMIN_FEE) => fee + admin - DISCOUNT;

export const activityFee = (a: Activity): number => {
  if (a.selectedDoctor) return priceOf(a.selectedDoctor.price);
  if (a.labStep) return a.labStep.price ?? 0;
  if (a.imagingStep) return a.imagingStep.price ?? 0;
  return 0;
};

export const timeLabel = (d: Date) =>
  `${d.toLocaleDateString('en-GB', { year: 'numeric', month: '2-digit', day: '2-digit' })} at ${d
    .toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}`;

export const dayLabel = (d: Date) => {
  const today = new Date();
  if (d.toDateString() === today.toDateString()) return 'Today';
  return d.toLocaleDateString('en-US', { weekday: 'short', day: 'numeric' });
};

export const pushActivity = (
  setActivities: React.Dispatch<React.SetStateAction<Activity[]>>,
  activity: Activity
) => setActivities((a) => [...a, activity]);

export const updateActivity = (
  setActivities: React.Dispatch<React.SetStateAction<Activity[]>>,
  id: string,
  patch: Partial<Activity>
) => setActivities((list) => list.map((a) => (a.id === id ? { ...a, ...patch } : a)));

export const stepToQueueStage = (stage: QueueStage): QueueStage => stage;

export const availabilityFor = (d: Doctor): DoctorAvailability[] => d.availableDates;

export const summarizeTests = (steps: ClinicStep[]) => steps.map((s) => s.name);
