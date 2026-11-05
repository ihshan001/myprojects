import React, { useState } from 'react';
import { useClinic } from '../data/store';
import { Screen } from '../components/shell';
import { Field, Icon, PrimaryButton } from '../components/ui';
import { COLORS } from '../data/clinic';

/* ------------------------------ Landing ------------------------------ */

export function LandingScreen() {
  const { push, session } = useClinic();
  return (
    <Screen statusLight>
      <div style={{ position: 'relative' }}>
        <div
          style={{
            height: 430,
            backgroundImage: 'url(/assets/ui/hero-bg.png)',
            backgroundSize: 'cover',
            backgroundPosition: 'center top',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            borderRadius: '0 0 44px 44px',
            overflow: 'hidden',
          }}
        >
          <img src="/assets/ui/logo.png" alt="Medisync" style={{ width: 236, opacity: 0.92 }} />
        </div>
      </div>
      <div className="fade-in" style={{ padding: '20px 30px 30px' }}>
        <div className="h1" style={{ fontSize: 27, color: '#111' }}>Your health, synced.</div>
        <p className="muted" style={{ fontSize: 15, lineHeight: 1.5, marginTop: 10, fontWeight: 500 }}>
          Book visits, view lab results, and manage your care in one tap.
        </p>

        <div className="row" style={{ justifyContent: 'flex-end', marginTop: 34 }}>
          <button
            className="row gap-10"
            onClick={() => push(session.isLoggedIn ? 'services' : 'login')}
            style={{ alignItems: 'center' }}
          >
            <span style={{ fontSize: 14, fontWeight: 700, color: '#6b7280' }}>Continue</span>
            <span style={{
              width: 46, height: 46, borderRadius: '50%', background: '#0b0f14', color: '#fff',
              display: 'flex', alignItems: 'center', justifyContent: 'center',
            }}>
              {Icon.arrowRight({ size: 19 })}
            </span>
          </button>
        </div>
      </div>
    </Screen>
  );
}

/* ------------------------------- Login ------------------------------- */

export function LoginScreen() {
  const { push, startGuestSession, startSSOSession } = useClinic();
  return (
    <Screen>
      <div style={{ padding: '26px 24px' }}>
        <div className="h1">Welcome Back</div>
        <p className="muted" style={{ fontSize: 14, marginTop: 8 }}>
          Sign in to manage your appointments and services
        </p>

        <div style={{ marginTop: 30 }}>
          <PrimaryButton onClick={() => push('phone')}>Continue with Phone Number</PrimaryButton>
        </div>

        <div className="row gap-12" style={{ margin: '26px 0' }}>
          <div style={{ height: 1, background: '#e5e7eb', flex: 1 }} />
          <span className="small muted">Or continue with</span>
          <div style={{ height: 1, background: '#e5e7eb', flex: 1 }} />
        </div>

        <div className="stack gap-12">
          <button
            className="row gap-12"
            onClick={() => { startSSOSession('apple'); push('services'); }}
            style={{ border: '1.5px solid #e5e7eb', borderRadius: 100, padding: '14px 18px', justifyContent: 'center', fontSize: 15, fontWeight: 700 }}
          >
            <svg width="18" height="20" viewBox="0 0 384 512" fill="#0b0f14"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>
            Sign in with Apple
          </button>
          <button
            className="row gap-12"
            onClick={() => { startSSOSession('google'); push('services'); }}
            style={{ border: '1.5px solid #e5e7eb', borderRadius: 100, padding: '14px 18px', justifyContent: 'center', fontSize: 15, fontWeight: 700 }}
          >
            <svg width="19" height="19" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.5l6.7-6.7C35.6 2.4 30.2 0 24 0 14.6 0 6.4 5.4 2.5 13.2l7.8 6.1C12.2 13.3 17.6 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.6c0-1.6-.1-3.1-.4-4.6H24v9.1h12.4c-.5 2.9-2.2 5.4-4.7 7l7.6 5.9c4.4-4.1 6.8-10.1 6.8-17.4z"/><path fill="#FBBC05" d="M10.3 28.7A14.6 14.6 0 019.5 24c0-1.6.3-3.2.8-4.7l-7.8-6.1A24 24 0 000 24c0 3.9.9 7.5 2.5 10.8l7.8-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 12-2.1 16-5.8l-7.6-5.9c-2.1 1.4-4.8 2.3-8.4 2.3-6.4 0-11.8-3.8-13.7-9.9l-7.8 6.1C6.4 42.6 14.6 48 24 48z"/></svg>
            Sign in with Google
          </button>
        </div>

        <button
          className="text-btn"
          style={{ width: '100%', padding: 18, marginTop: 10 }}
          onClick={() => { startGuestSession(); push('services'); }}
        >
          Continue as Guest
        </button>
      </div>
    </Screen>
  );
}

/* --------------------------- Phone number --------------------------- */

export function PhoneScreen() {
  const { push, back, startPhoneSession } = useClinic();
  const [phone, setPhone] = useState('');
  const valid = phone.replace(/\D/g, '').length >= 9;

  return (
    <Screen>
      <div style={{ padding: '10px 24px 24px' }}>
        <button className="back-btn" style={{ marginBottom: 22 }} onClick={back}>{Icon.back({ size: 18 })}</button>

        <div className="h1" style={{ fontSize: 26 }}>Enter Phone Number</div>
        <p className="muted" style={{ fontSize: 14, marginTop: 8 }}>We'll send you a verification code</p>

        <div style={{ marginTop: 26 }}>
          <span className="field-label">Phone Number</span>
          <div className="row" style={{ border: `1.5px solid ${valid ? COLORS.primary : '#d8dee6'}`, borderRadius: 14, padding: '4px 6px 4px 14px', transition: 'border-color .18s' }}>
            <span style={{ fontWeight: 700, fontSize: 15, color: '#334155' }}>+94</span>
            <input
              style={{ border: 'none', flex: 1, padding: '12px 10px', fontSize: 15, background: 'transparent' }}
              placeholder="77 123 4567"
              inputMode="numeric"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
            />
          </div>
        </div>

        <div style={{ marginTop: 22 }}>
          <PrimaryButton
            disabled={!valid}
            onClick={() => { startPhoneSession(phone); push('otp', { phone }); }}
          >
            Continue
          </PrimaryButton>
        </div>

        <p className="xs muted" style={{ textAlign: 'center', marginTop: 26, lineHeight: 1.6 }}>
          By continuing, you agree to our{' '}
          <span style={{ color: COLORS.primary, fontWeight: 700 }}>Terms of Service</span> and{' '}
          <span style={{ color: COLORS.primary, fontWeight: 700 }}>Privacy Policy</span>
        </p>
      </div>
    </Screen>
  );
}

/* -------------------------------- OTP -------------------------------- */

export function OTPScreen({ phone }: { phone: string }) {
  const { push, back, startPhoneSession } = useClinic();
  const [digits, setDigits] = useState(['', '', '', '']);
  const complete = digits.every((d) => d !== '');

  const setAt = (i: number, v: string) => {
    const clean = v.replace(/\D/g, '').slice(-1);
    setDigits((d) => d.map((x, idx) => (idx === i ? clean : x)));
    if (clean && i < 3) {
      const next = document.getElementById(`otp-${i + 1}`) as HTMLInputElement | null;
      next?.focus();
    }
  };

  return (
    <Screen>
      <div style={{ padding: '10px 24px 24px' }}>
        <button className="back-btn" style={{ marginBottom: 22 }} onClick={back}>{Icon.back({ size: 18 })}</button>

        <div className="h1" style={{ fontSize: 26 }}>Enter Verification Code</div>
        <p className="muted" style={{ fontSize: 14, marginTop: 8, lineHeight: 1.5 }}>
          We've sent a 4-digit code to your phone number {phone || '+94 77 123 4567'}
        </p>

        <p className="small muted" style={{ textAlign: 'center', margin: '24px 0 16px' }}>
          This code expires in 5 minutes.
        </p>

        <div className="otp-row">
          {digits.map((d, i) => (
            <input
              key={i}
              id={`otp-${i}`}
              className="otp-box"
              value={d}
              inputMode="numeric"
              maxLength={1}
              onChange={(e) => setAt(i, e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Backspace' && !digits[i] && i > 0) {
                  const prev = document.getElementById(`otp-${i - 1}`) as HTMLInputElement | null;
                  prev?.focus();
                }
              }}
            />
          ))}
        </div>

        <p className="xs" style={{ textAlign: 'center', marginTop: 12, color: '#94a3b8' }}>
          Demo: any 4 digits will verify
        </p>

        <div style={{ marginTop: 22 }}>
          <PrimaryButton disabled={!complete} onClick={() => { startPhoneSession(phone); push('services'); }}>
            Verify
          </PrimaryButton>
        </div>

        <div className="row gap-6" style={{ justifyContent: 'center', marginTop: 22 }}>
          <span className="small muted">Didn't receive code?</span>
          <button className="text-btn" style={{ fontSize: 13 }} onClick={() => setDigits(['', '', '', ''])}>Resend</button>
        </div>
      </div>
    </Screen>
  );
}
