# ClinicQueue — live web preview

A faithful, fully interactive **browser replica** of the native SwiftUI iOS app in
[`../clinic-queue-management-system-main`](../clinic-queue-management-system-main)
(Medisync / ClinicQueue — clinic visit + queue management).

The original project is an Xcode/SwiftUI target, so it cannot run in a browser as-is.
This app ports the same screens, flows, design tokens and real artwork to React + Vite so
the whole system can be walked through live.

## Run

```bash
npm install
npm run dev        # http://localhost:5173
npm run build      # production build
```

## What is ported

| Swift source | Web screen |
| --- | --- |
| `LandingView`, `LoginView`, `PhoneNumberEntryView`, `OTPVerificationView` | landing / guest / phone-OTP sign in |
| `ServicesView` | service hub + active-queue banner |
| `PatientDetailsFormView` → `SymptomsSelection` → `AppointmentStarterView` | consultation intake, symptom triage, doctor auto-assignment, payment |
| `LabList`, `LabTestDetailsView`, `ImagingDetailsView` | 10 lab + 10 imaging tests, selection, pricing |
| `PaymentView`, `PaymentThroughCashView`, `PaymentProcessingView`, `PaymentStatusView` | card + OTP, counter QR, processing, success / cancel & refund |
| `Queue` | live queue engine (wait → next → ready → in progress → completed), requested vs completed services, recheck with doctor, leave clinic |
| `LabQueueTrackerView`, `LabQueueReady`, `LabQueueDone` | per-test step tracker with floor mini-maps |
| `PharmacyView`, `MyCartView`, `PrescriptionUploadModal` | drug catalogue, cart, prescription upload |
| `FindDoctorView`, `DoctorAvailabilitySelector`, `DoctorAppointmentStarterView` | channeling: 25 doctors, 18 specialties, dates, queues |
| `MapView`, `BlueprintCanvas`, `LabDirectionMiniMap` | interactive ground / 2nd-floor indoor blueprint map |
| `ProfileView`, `MyProfileView`, `NotificationView`, `TransactionHistory`, `TestHistoryView`, `ChannelingHistoryView` | profile and history stack |

Data (`Core/Data/*`) — doctors, lab/imaging tests, symptoms, test-recommendation rules,
pharmacy items — is ported 1:1. Images come from `ClinicQueue/Fonts/Assets.xcassets`
and were resized for the web.
