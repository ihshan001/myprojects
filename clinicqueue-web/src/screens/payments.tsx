import React, { useEffect, useState } from 'react';
import { useClinic, totalFor } from '../data/store';
import { ADMIN_FEE, COLORS, money, paymentMethods } from '../data/clinic';
import { Screen } from '../components/shell';
import { BackHeader, Field, Icon, PrimaryButton, QRCode, Badge } from '../components/ui';
import { PayOption } from './clinicFlow';

/* ------------------------------ Card pay ------------------------------ */

export function PaymentScreen() {
  const { push, back, payment } = useClinic();
  const [name, setName] = useState('');
  const [number, setNumber] = useState('');
  const [cvv, setCvv] = useState('');
  const [exp, setExp] = useState('');
  const [method, setMethod] = useState(paymentMethods[0].id);
  const [otpOpen, setOtpOpen] = useState(false);
  const [otp, setOtp] = useState(['', '', '', '']);
  const [seconds, setSeconds] = useState(349);

  useEffect(() => {
    if (!otpOpen) return;
    setSeconds(349);
    const id = setInterval(() => setSeconds((s) => (s > 0 ? s - 1 : 0)), 1000);
    return () => clearInterval(id);
  }, [otpOpen]);

  const amount = payment?.amount ?? 0;
  const admin = payment?.adminFee ?? ADMIN_FEE;
  const total = totalFor(amount, admin);

  const validExp = (() => {
    const parts = exp.split('/');
    if (parts.length !== 2) return false;
    const yy = parseInt(parts[0], 10);
    const mm = parseInt(parts[1], 10);
    if (!(mm >= 1 && mm <= 12)) return false;
    const now = new Date();
    const full = 2000 + yy;
    if (full > now.getFullYear()) return true;
    if (full === now.getFullYear()) return mm >= now.getMonth() + 1;
    return false;
  })();

  const cardValid = name.trim().length > 0 && !/\d/.test(name) && number.replace(/\s/g, '').length >= 15 && cvv.length >= 3 && validExp;
  const otpComplete = otp.every((d) => d !== '');

  return (
    <Screen>
      <div style={{ padding: '6px 20px 30px' }}>
        <BackHeader title="Payment" onBack={back} />

        <div style={{ marginTop: 14 }}>
          <div className="h2" style={{ marginBottom: 14 }}>Payment Details</div>
          {[
            { label: payment?.label ?? 'Consultation', value: money(amount) },
            { label: 'Admin Fee', value: money(admin) },
            { label: 'Additional Discount', value: money(0) },
            { label: 'Total', value: money(total), total: true },
          ].map((r) => (
            <div key={r.label} className="between" style={{ marginBottom: 11 }}>
              <span style={{ fontSize: 15, color: (r as any).total ? '#0b0f14' : COLORS.lable, fontWeight: (r as any).total ? 800 : 500 }}>{r.label}</span>
              <span style={{ fontSize: 16, fontWeight: 800 }}>{r.value}</span>
            </div>
          ))}
        </div>

        <div style={{ marginTop: 24 }}>
          <div className="h2" style={{ marginBottom: 12 }}>Choose Payment Method</div>
          <div className="row gap-10">
            {paymentMethods.map((m) => (
              <button
                key={m.id}
                onClick={() => setMethod(m.id)}
                style={{
                  width: 55, height: 55, borderRadius: 11,
                  background: method === m.id ? COLORS.primary : 'rgba(100,116,139,.16)',
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                }}
              >
                <img src={m.icon} alt={m.name} style={{ width: 30, height: 30, objectFit: 'contain' }} />
              </button>
            ))}
          </div>
        </div>

        <div style={{ marginTop: 24 }}>
          <div className="h2" style={{ marginBottom: 14 }}>Card Info</div>
          <div className="stack gap-12">
            <Field placeholder="Name on the Card" value={name} onChange={setName} />
            <Field placeholder="Card Number" value={number} onChange={(v) => setNumber(v.replace(/[^\d ]/g, '').slice(0, 19))} inputMode="numeric" />
            <div className="row gap-12">
              <div style={{ width: 100 }}>
                <Field placeholder="CVV" value={cvv} onChange={(v) => setCvv(v.replace(/\D/g, '').slice(0, 4))} inputMode="numeric" />
              </div>
              <div className="grow">
                <Field placeholder="Expiration Date (YY/MM)" value={exp} onChange={(v) => setExp(v.replace(/[^\d/]/g, '').slice(0, 5))} inputMode="numeric" />
              </div>
            </div>
          </div>
        </div>

        <div style={{ marginTop: 26 }}>
          <PrimaryButton disabled={!cardValid} onClick={() => setOtpOpen(true)}>{payment?.title === 'Consultation' ? 'Pay & Book' : 'Pay Now'}</PrimaryButton>
        </div>

        <p className="xs muted" style={{ textAlign: 'center', marginTop: 14, lineHeight: 1.6 }}>
          Demo tip — any name, a 16-digit number, CVV 123 and expiry 28/12 will pass.
        </p>
      </div>

      {otpOpen && (
        <div className="overlay" onClick={() => setOtpOpen(false)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()}>
            <div className="stack gap-16" style={{ textAlign: 'center' }}>
              <div className="semi" style={{ fontSize: 14, lineHeight: 1.5 }}>
                Please enter the OTP code you received on your (079 878 8779) phone.
              </div>
              <div className="otp-row">
                {otp.map((d, i) => (
                  <input
                    key={i}
                    id={`pay-otp-${i}`}
                    className="otp-box"
                    style={{ width: 50, height: 54 }}
                    value={d}
                    inputMode="numeric"
                    maxLength={1}
                    onChange={(e) => {
                      const v = e.target.value.replace(/\D/g, '').slice(-1);
                      setOtp((o) => o.map((x, idx) => (idx === i ? v : x)));
                      if (v && i < 3) (document.getElementById(`pay-otp-${i + 1}`) as HTMLInputElement | null)?.focus();
                    }}
                  />
                ))}
              </div>
              <div className="small muted">
                {seconds > 0 ? (
                  <>The OTP code will expire in <span className="bold" style={{ color: '#0b0f14' }}>{Math.floor(seconds / 60)} minutes and {String(seconds % 60).padStart(2, '0')} seconds</span></>
                ) : (
                  <span style={{ color: COLORS.error }}>OTP expired</span>
                )}
              </div>
              <p className="xs" style={{ color: '#94a3b8' }}>Demo: any 4 digits will confirm</p>
              <PrimaryButton disabled={!otpComplete} onClick={() => { setOtpOpen(false); push('processing'); }}>Confirm</PrimaryButton>
              <button className="text-btn" style={{ fontSize: 13, color: COLORS.placeholder }} onClick={() => setOtp(['', '', '', ''])}>Resend</button>
            </div>
          </div>
        </div>
      )}
    </Screen>
  );
}

/* ------------------------------ Cash pay ------------------------------ */

export function CashPayScreen() {
  const { push, back, payment } = useClinic();
  const amount = payment?.amount ?? 0;
  const total = totalFor(amount, payment?.adminFee ?? ADMIN_FEE);

  useEffect(() => {
    const t = setTimeout(() => push('processing'), 4000);
    return () => clearTimeout(t);
  }, [push]);

  return (
    <Screen>
      <div style={{ padding: '6px 20px 30px' }}>
        <BackHeader title="Pay at Counter" onBack={back} />
        <div className="stack gap-12" style={{ alignItems: 'center', marginTop: 26 }}>
          <p className="small muted" style={{ textAlign: 'center', lineHeight: 1.6, maxWidth: 280 }}>
            Please present this QR code at the payment counter to complete your payment.
            Our staff will scan the code and assist you.
          </p>
          <div style={{ background: '#fff', padding: 14, borderRadius: 18, boxShadow: '0 8px 24px -14px rgba(15,23,42,.4)', border: '1px solid #eef2f6' }}>
            <QRCode content={`John Doe|B12345|${money(total)}`} size={188} />
          </div>
          <div className="xs" style={{ color: '#64748b', fontVariantNumeric: 'tabular-nums' }}>
            John Doe&nbsp; | &nbsp;B12345&nbsp; | &nbsp;{money(total)}
          </div>
          <div className="row gap-6" style={{ marginTop: 6 }}>
            <span className="spinner" style={{ width: 16, height: 16, borderWidth: 2 }} />
            <span className="xs muted">Waiting for counter confirmation…</span>
          </div>
        </div>
      </div>
    </Screen>
  );
}

/* ----------------------------- Processing ----------------------------- */

export function ProcessingScreen() {
  const { push, payment } = useClinic();
  useEffect(() => {
    const t = setTimeout(() => {
      push('paymentStatus');
    }, 3200);
    return () => clearTimeout(t);
  }, [push, payment]);

  return (
    <Screen>
      <div className="stack gap-16" style={{ alignItems: 'center', padding: '70px 30px', textAlign: 'center' }}>
        <div className="h2">Payment Processing</div>
        <div className="spinner" style={{ marginTop: 10 }} />
        <p className="small muted" style={{ lineHeight: 1.6 }}>Please wait while we confirm your payment.<br />This usually takes a few seconds.</p>
      </div>
    </Screen>
  );
}

/* --------------------------- Payment status --------------------------- */

export function PaymentStatusScreen() {
  const {
    push, back, payment, setPayment, visit, activities, setActivities,
    currentService, appointments, setAppointments, setTestHistory, setCurrentAppointmentId,
    currentAppointmentId,
  } = useClinic();
  const [refundOpen, setRefundOpen] = useState(false);
  const [qrOpen, setQrOpen] = useState(false);
  const [secondsLeft, setSecondsLeft] = useState(300);
  const [code] = useState(() => `REFUND-${Math.floor(100000 + Math.random() * 900000)}`);

  useEffect(() => {
    if (!qrOpen) return;
    const id = setInterval(() => setSecondsLeft((s) => (s > 0 ? s - 1 : 0)), 1000);
    return () => clearInterval(id);
  }, [qrOpen]);

  const amount = payment?.amount ?? 0;
  const total = totalFor(amount, payment?.adminFee ?? ADMIN_FEE);

  const onContinue = () => {
    const target = payment?.onSuccessScreen ?? 'services';
    // apply side-effects of a successful payment
    if (currentService === 'appointment') {
      // appointments are appended when booking; nothing else needed
    } else {
      setActivities((list) =>
        list.map((a) => (a.isSelected && a.service === currentService ? { ...a, queueStage: 'wait', stage: 'inQueue' } : a))
      );
    }
    push(target, payment?.onSuccessParams);
  };

  const refund = () => {
    setRefundOpen(false);
    setQrOpen(true);
  };

  const finishRefund = () => {
    if (currentService === 'appointment') {
      setActivities((l) => l.filter((a) => a.service !== 'appointment'));
      if (currentAppointmentId) {
        setAppointments((list) =>
          list.map((a) =>
            a.id === currentAppointmentId ? { ...a, status: 'refunded', isRefunded: true } : a
          )
        );
        setCurrentAppointmentId(null);
      }
    } else {
      setActivities((l) => l.filter((a) => a.service !== 'clinic'));
    }
    setPayment(null);
    push('channeling');
  };

  return (
    <Screen>
      <div style={{ padding: '10px 20px 30px', textAlign: 'center' }}>
        <div className="h2" style={{ marginTop: 12 }}>Payment Successful</div>
        <div style={{ display: 'flex', justifyContent: 'center', margin: '18px 0 10px', color: '#22a06b' }}>
          {Icon.checkCircle({ size: 70, color: '#22a06b' })}
        </div>
        <p className="small" style={{ color: COLORS.lable, lineHeight: 1.6 }}>Your payment has been received.</p>

        <div style={{ textAlign: 'left', marginTop: 24 }}>
          <div className="h2" style={{ marginBottom: 14 }}>Payment Details</div>
          {[
            { label: payment?.label ?? 'Consultation', value: money(amount) },
            { label: 'Admin Fee', value: money(payment?.adminFee ?? ADMIN_FEE) },
            { label: 'Additional Discount', value: money(0) },
            { label: 'Total', value: money(total), total: true },
          ].map((r) => (
            <div key={r.label} className="between" style={{ marginBottom: 11 }}>
              <span style={{ fontSize: 15, color: (r as any).total ? '#0b0f14' : COLORS.lable, fontWeight: (r as any).total ? 800 : 500 }}>{r.label}</span>
              <span style={{ fontSize: 16, fontWeight: 800 }}>{r.value}</span>
            </div>
          ))}
        </div>

        <div style={{ marginTop: 24 }}>
          <PrimaryButton onClick={onContinue}>Continue</PrimaryButton>
        </div>
        <button
          onClick={() => setRefundOpen(true)}
          style={{ marginTop: 16, color: '#e5484d', fontSize: 14, fontWeight: 700 }}
        >
          Cancel &amp; Refund
        </button>
        <p className="xs muted" style={{ marginTop: 10, lineHeight: 1.5 }}>
          Booking ref: {payment?.label ?? 'visit'} · paid by {payment?.method === 'cash' ? 'counter cash' : 'card'}
        </p>
      </div>

      {refundOpen && (
        <div className="overlay" onClick={() => setRefundOpen(false)}>
          <div className="modal-card" style={{ textAlign: 'center' }} onClick={(e) => e.stopPropagation()}>
            <div style={{ display: 'flex', justifyContent: 'center', marginBottom: 12, color: '#f59e0b' }}>
              {Icon.undo({ size: 44 })}
            </div>
            <div className="h3">Cancel &amp; Refund</div>
            <p className="small muted" style={{ margin: '10px 0 18px', lineHeight: 1.55 }}>
              Are you sure you want to cancel and request a refund? This action cannot be undone.
            </p>
            <div className="row gap-12">
              <button className="grow" style={{ padding: '12px 0', borderRadius: 11, background: '#f1f5f9', fontWeight: 700, fontSize: 14 }} onClick={() => setRefundOpen(false)}>
                No, Keep It
              </button>
              <button className="grow" style={{ padding: '12px 0', borderRadius: 11, background: '#e5484d', color: '#fff', fontWeight: 700, fontSize: 14 }} onClick={refund}>
                Yes, Refund
              </button>
            </div>
          </div>
        </div>
      )}

      {qrOpen && (
        <div className="overlay">
          <div className="modal-card" style={{ textAlign: 'center' }}>
            <div style={{ display: 'flex', justifyContent: 'center', color: '#f59e0b' }}>{Icon.undo({ size: 46 })}</div>
            <div className="h3" style={{ marginTop: 10 }}>Refund Initiated</div>
            <p className="small muted" style={{ margin: '10px 0 14px', lineHeight: 1.55 }}>
              Please go to the payment counter and present this QR code to collect your refund amount.
            </p>
            <div style={{ display: 'flex', justifyContent: 'center' }}>
              <QRCode content={code} size={168} />
            </div>
            <div className="xs" style={{ color: '#94a3b8', marginTop: 10, fontFamily: 'monospace' }}>{code}</div>
            <div className="xs" style={{ color: '#94a3b8', marginTop: 4 }}>
              Window closes in {Math.floor(secondsLeft / 60)}:{String(secondsLeft % 60).padStart(2, '0')}
            </div>
            <button onClick={finishRefund} style={{ marginTop: 16, color: '#e5484d', fontWeight: 700, fontSize: 14 }}>Close</button>
          </div>
        </div>
      )}
    </Screen>
  );
}
