# QueuePilot — Screen inventory & screen designs

**Status:** Design stage · **102 screens** across 5 roles and 12 modules.

Each entry gives: purpose · user · layout · navigation · primary action ·
secondary actions · data · states · responsive behaviour · connections.

**Notation**

- **Page** = its own URL. **Panel** = a region of a page. **State** = an
  alternate rendering of a page. **Dialog** = overlay over a page. **Route** =
  no chrome at all (public display / kiosk).
- `R` receptionist · `D` doctor · `O` clinic owner · `P` patient (public) ·
  `A` platform admin.

---

## Contents

| # | Module | Screens |
|---|---|---|
| 1 | Authentication | 5 |
| 2 | Clinic onboarding | 7 |
| 3 | Receptionist | 12 |
| 4 | Doctor workspace | 9 |
| 5 | Patient experience | 8 |
| 6 | Clinic management | 13 |
| 7 | Notifications | 7 |
| 8 | Reports | 7 |
| 9 | Subscription & billing | 7 |
| 10 | Organisation & branches | 5 |
| 11 | Platform administration | 12 |
| 12 | System screens & states | 10 |

---

# 1. Authentication — 5 screens

### 1.1 Login — `Page` · all roles · `/login`
- **Purpose** Sign in. **User** Everyone.
- **Layout** Centred 400px card on `--qp-canvas`. Logo, title, two fields, primary button. No rail, no nav.
- **Primary** *Sign in*. **Secondary** *Forgot password?*, *Create a clinic account*.
- **Data** Email, password, optional *Remember me*.
- **States** Invalid credentials (inline, generic — never reveal which part was wrong); account locked after N attempts with a countdown; unverified email nudges resend; suspended clinic shows a calm explanation with a support link, **not** a generic error.
- **Responsive** Card full-width on mobile. Inputs 48px on touch.
- **Connects to** Role home page (role-dependent), Forgot password, Clinic registration.

### 1.2 Forgot password — `Page` · all · `/forgot-password`
- **Purpose** Request a reset link. **Layout** Centred 400px card.
- **Primary** *Send reset link*. **Secondary** *Back to sign in*.
- **Data** Email.
- **States** Always the same success message whether or not the address exists — do not leak account existence. Rate-limited with a clear wait message. If no mail transport is configured, show the link once to the requester.
- **Connects to** Login, Password reset.

### 1.3 Password reset — `Page` · all · `/reset-password?token=…`
- **Purpose** Set a new password from a valid link.
- **Primary** *Set new password*. **Data** New password, confirmation.
- **States** Token expired → explanation + *Request a new link*. Strength meter shown live. Same-password-as-old rejected.
- **Connects to** Login.

### 1.4 Email verification — `Page` · all · `/verify-email?token=…`
- **Purpose** Confirm an address. **Primary** *Resend verification*.
- **States** Verified (green, auto-redirect in 3s), invalid token, already verified.
- **Connects to** Login, Onboarding welcome.

### 1.5 User profile — `Page` · all · `/profile`
- **Purpose** Own account details and password.
- **Layout** Inside the app shell. Narrow 640px column.
- **Primary** *Save changes*. **Secondary** *Change password* (dialog), sign out of other sessions, delete account (owner-gated).
- **Data** Name, email (with verification state), phone, avatar initials, language, timezone, role (read-only), last login.
- **States** Email change requires re-verification. Password change requires the current password.
- **Connects to** Everywhere via the user menu.

---

# 2. Clinic onboarding — 7 screens

A wizard, because a new clinic has nothing to see yet. **Progress is preserved
between steps** and the wizard can be abandoned and resumed — a half-configured
clinic is a real state, not an error.

### 2.1 Welcome — `Page` · O · `/onboarding`
- **Purpose** Set expectations before asking for data.
- **Layout** Centred, generous. Product name, one-line promise, *Get started*.
- **Primary** *Set up my clinic*.
- **Content** Three sentences on what the next four steps are.
- **Connects to** 2.2.

### 2.2 Clinic registration — `Page` · O · `/onboarding/register`
- **Purpose** Create the tenant and the owner account in one step.
- **Primary** *Create clinic account*. **Data** Clinic name, city, district; owner name, email, phone, password.
- **States** Email already registered → inline, with a login link.
- **Connects to** 2.3.

### 2.3 Clinic details — `Page` · O · `/onboarding/clinic`
- **Purpose** The information that appears on tokens and public pages.
- **Primary** *Continue*. **Data** Phone, email, address line, city, district, timezone, currency, logo upload.
- **Note** Timezone is asked here, not later — it determines what "today" means for every queue.
- **Connects to** 2.4.

### 2.4 Add first doctor — `Page` · O · `/onboarding/doctor`
- **Purpose** Nothing works without a doctor.
- **Primary** *Add doctor & continue*. **Secondary** *Skip for now* (allowed, but the next step warns).
- **Data** Name, specialty (creatable inline), room (creatable inline), consultation fee, **queue prefix** — prefilled `A`, with the live example `A001` shown next to the field.
- **Connects to** 2.5.

### 2.5 Configure working hours — `Page` · O · `/onboarding/hours`
- **Purpose** Clinic opening hours drive booking availability and "open for the day".
- **Primary** *Save hours & continue*. **Data** Seven rows: closed checkbox + open/close times. Copy-to-all-days shortcut. Booking window (days ahead).
- **Connects to** 2.6.

### 2.6 Invite receptionist — `Page` · O · `/onboarding/team`
- **Purpose** Get the second pair of hands in.
- **Primary** *Send invitation*. **Secondary** *Do this later*.
- **Data** Name, email, role.
- **States** If no mail transport is configured, **show the invitation link once** so it can be sent by hand.
- **Connects to** 2.7.

### 2.7 Onboarding completion — `Page` · O · `/onboarding/done`
- **Purpose** Land the clinic in the live product, not in a settings page.
- **Primary** *Open my clinic dashboard*. **Secondary** *Set up the waiting-room screen*, *Issue a test token*.
- **Content** A short "you're ready" summary: clinic, doctor, hours, team.
- **Connects to** Receptionist/owner dashboard, 3.1.

---

# 3. Receptionist — 12 screens

The receptionist workflow is the product. It must be completable without
leaving the overview for the common case.

### 3.1 Receptionist dashboard / Overview — `Page` · R, O · `/app`
- **Purpose** "How are we doing right now?" at a glance. **User** Receptionist, owner.
- **Layout** Greeting strip (greeting, date & time, live/stale indicator, auto-refresh note, waiting-room-screen button) → **five stat tiles** (Waiting now · In consultation · Completed today · Average wait · Longest wait) → two columns: left **Live queue** panel, right rail (Patient tracking QR · Today at a glance · System status).
- **Primary** *Issue a token*. **Secondary** Open waiting-room screen, pause/reopen queue, view full queue, refresh now.
- **Live queue panel** Doctor tabs (up to three as real links, rest in a select — works without JS), *In consultation* card (blue), *Next in line* card (amber) with **Call next**, then *Waiting in queue* list. Each waiting row: position, token chip, priority badge, name, patient code, joined time, call count, the two most useful verbs inline and the rest behind `⋯`.
- **Data** Today's queues, entries, stats; polls `/app/panel` every 5s.
- **States** Empty ("No patients yet today" + *Issue the first token*); stale (amber "Stale" pill, last-updated timestamp); offline (explicit grey banner); no doctors configured (guided to add one).
- **Responsive** Stat strip swipes horizontally on phone. Rail cards stack below the queue panel. Bottom tab bar.
- **Connects to** 3.4 (registration), 3.8 (queue board), 3.10 (appointments), 5.7 (tracking).

### 3.2 Patient registration — `Page` · R, O · `/patients/new`
- **Purpose** Add a new patient to the clinic register.
- **Primary** *Register & issue token*. **Secondary** *Register only*, *Cancel*.
- **Data** Full name (required), phone (required, validated, duplicate-checked), gender, birth year (not full DOB — collects what is needed and nothing more), address, notes.
- **States** Duplicate detection on phone: *"A patient with this number already exists — open them?"* with a link. Field-level validation inline.
- **Responsive** Single column; 48px inputs on touch.
- **Connects to** 3.3, 3.4, 6.1.

### 3.3 Patient search — `Panel` · R, O, D · embedded in 3.1/3.10
- **Purpose** Find an existing patient in under two seconds.
- **Layout** Search box with autofocus; results as a list of rows: name, phone, patient code, age, and **"In OPD now · A001"** when already queued today.
- **Primary** Select a patient. **Secondary** *Register "…" as a new walk-in* when nothing matches.
- **States** No match → offer to register. Too many matches → show first 10 with a *Refine your search* hint.
- **Accessibility** Works with JavaScript disabled: results are real links, with a plain form in `<noscript>`.
- **Connects to** 3.4, 3.5.

### 3.4 Patient details — `Page` · R, O · `/patients/{id}`
- **Purpose** Everything known about one patient.
- **Layout** Header (avatar initials, name, patient code, age/gender, phone) → *Edit record* panel → tabs: Visits · Appointments · Contact.
- **Primary** *Issue token* (opens 3.4a with this patient preselected). **Secondary** Edit, print tracking slip, copy tracking link.
- **Data** Demographics, contact, visit history (token, date, doctor, status, wait), appointment history, notes.
- **States** No visits yet → empty with *Issue first token*.
- **Connects to** 3.5, 3.6, 3.10, 5.7.

### 3.4a Issue token — `Dialog`/`Page` · R, O · `/queue/new`
- **Purpose** The single most important screen in the product.
- **Layout** Two steps on one page. **Step 1 · Who is it?** — live search (3.3) or walk-in fields. **Step 2 · Issue the token** — queue select (defaulting to the queue you arrived from) and a button that says exactly what will happen: *"Issuing to Anura Dissanayake…"*.
- **Primary** *Issue token*. **Secondary** Check in a booking (a list of today's appointments with **Check in**).
- **States** Already in a queue today → blocked with the existing token shown. Queue closed → explanatory, not a dead button.
- **On success** *Token issued* — token, **Print token** (slip with QR), **Copy tracking link**.
- **Connects to** 3.1, 3.8, 5.7.

### 3.5 Patient visit history — `Panel` · R, O, D · on 3.4
- **Purpose** Past visits without opening each one.
- **Data** Token · Date · Doctor · Status · Wait · Consultation length. Rows link to 3.7.
- **States** Empty; paginated beyond 25.

### 3.6 Patient edit — `Panel` · R, O · on 3.4
- **Purpose** Correct a record. **Primary** *Save changes*.
- **Data** Name, phone, gender, birth year, address, notes. Phone changes re-run duplicate detection.

### 3.7 Token / visit details — `Page` · R, O, D · `/queue/entry?id=…`
- **Purpose** One token's whole journey.
- **Layout** Header (token chip, patient, status, doctor, room) → Actions (only the verbs legal from the current state and role) → Service steps (if a workflow is configured) → History (every event with from/to state, actor, timestamp).
- **Primary** The state-appropriate primary action (Call / Start / Complete).
- **Secondary** Skip, no-show, priority, hold, transfer, cancel — progressively disclosed, uncommon ones behind `⋯`.
- **Reason dialogs** Priority **requires** a reason (written to the audit log). Hold defaults to "Stepped away" — a hold with no reason is a mystery row on the board. Cancel is optional. Transfer asks for a destination **from a list of today's open queues**, never "type 1, 2 or 3".
- **States** Transferred shows the origin queue. Cancelled shows who and why.
- **Connects to** 3.1, 3.8, 8.x.

### 3.8 Queue overview / board — `Page` · R, O, D · `/queue`
- **Purpose** One doctor's queue, in full.
- **Layout** Header (back, queue name, open/paused badge, *doctor · specialty · room · date*) → controls (queue switcher with waiting counts, **scan box**, *New patient*) → **session banner** → three stat cards (Now serving · Waiting · Tokens issued) → full waiting list.
- **Primary** *Call next* (blocked with an explanation until the session starts). **Secondary** Start session, scan/enter a token, switch queue, new patient.
- **Scan box** "Scan a slip or type A012". A barcode reader is just a keyboard — it types and presses Enter, so this is a real autofocused form, and wide enough to hold a whole tracking URL (which is what the slip's QR contains).
- **Session banner** Not started (amber): *"Tokens are still being issued and queued. Press start when the doctor is ready — calling is blocked until then."* Running (green): started-at time and waiting count. Paused/closed (grey): *"Patients keep their place. Resume to continue calling."*
- **Ordering** Priority first, then arrival order. Called rows tinted, priority rows with a red left border.
- **Responsive** Row actions drop to their own line on a phone so the name is never squeezed.
- **Connects to** 3.1, 3.4a, 3.7, 4.x.

### 3.9 Queue pause / resume — `Dialog` · R, O, D · on 3.8
- **Purpose** Stop calling without losing anyone's place.
- **Primary** *Pause queue* / *Resume queue*. Optional reason. Confirmed.
- **Note** Pausing is not closing: tokens may still be issued unless the clinic policy says otherwise, and the waiting-room screen shows the paused state.

### 3.10 Appointment list — `Page` · R, O · `/appointments`
- **Purpose** Today's bookings and the ability to act on them.
- **Layout** Filter bar (date, doctor, status, *Today*) → day list → right column sticky *Book an appointment* on desktop.
- **Primary per row** *Check in* (for a booked appointment) — **checking in preserves the slot priority**, which is the entire point of an appointment. Others show a status badge.
- **Secondary** Reschedule, cancel, view patient, filter.
- **Data** Time (monospace), duration, patient, doctor, phone, **source** (web / phone / walk-in), status.
- **States** Empty day; no doctors configured (booking form disabled *with an explanation*).
- **Connects to** 3.11, 3.12, 3.13, 5.x.

### 3.11 Appointment registration — `Panel`/`Page` · R, O · `/appointments/new`
- **Purpose** Book on a patient's behalf, by phone or at the desk.
- **Primary** *Book appointment*. **Data** Patient (search or walk-in), phone, doctor, date, time (only real slots — doctor schedule ∩ clinic hours − holidays − leave − existing bookings, respecting the booking window), duration, notes.
- **States** Slot taken between render and submit → inline error with the next three free slots offered.
- **Connects to** 3.10.

### 3.12 Appointment details — `Page` · R, O, D · `/appointments/{id}`
- **Purpose** One booking. **Primary** *Check in*.
- **Secondary** Reschedule (dialog, only real slots), cancel (dialog with reason), open patient.
- **Data** Patient, doctor, room, date/time, duration, source, status history, linked queue entry once checked in.

### 3.13 Appointment check-in — `Dialog` · R, O · from 3.10/3.12
- **Purpose** Turn a booking into a queue visit without re-typing anything.
- **Primary** *Check in & issue token*. **Data** Patient (prefilled, read-only), queue (prefilled to the doctor's queue), confirm.
- **Note** The whole value of an appointment is that check-in keeps the slot priority. This dialog must not ask for data already known.

---

# 4. Doctor workspace — 9 screens

A deliberately minimal, separate workspace. No clinic administration is visible.
Controls are large — this is used one-handed on a tablet, in a hurry.

### 4.1 Doctor dashboard — `Page` · D · `/doctor`
- **Purpose** Own queue and session state, nothing else.
- **Layout** Greeting with own name → **session state card** (the whole top third) → own queue (waiting count, now serving, next) → own day at a glance.
- **Primary** Whatever the session state requires: *Start session* → *Call next* → *Start consultation* → *Complete*.
- **Secondary** Pause session, view own schedule.
- **Data** Own queues and entries only. Never another doctor's.
- **States** No session today → calm "You have no sessions scheduled today" with the schedule link.
- **Connects to** 4.2/4.3, 4.4, 4.7, 4.9.

### 4.2 Session not started — `State` · D · on 4.1
- **Purpose** Make the "you cannot call yet" rule self-explanatory.
- **Layout** Amber banner: *"You have not started today's session. Tokens are being issued and queued. Calling is blocked until you start."* With the waiting count visible — the doctor can see the room filling.
- **Primary** *Start session* (large, 48px).
- **Secondary** *Cancel today's session* — confirmed, with a reason. This is clinical authority over one's own list.

### 4.3 Active session — `State` · D · on 4.1
- **Layout** Green banner with started-at time and waiting count. Controls: *Pause session*, *End session*.
- **Primary** *Call next*.

### 4.4 Doctor's queue — `Page` · D · `/doctor/queue`
- **Purpose** The full own waiting list.
- **Layout** Own queue only. Waiting count, now serving, next, then the list: position, token, name (permitted), joined time, priority, status, actions for the current state.
- **Primary** *Call next*. **Secondary** Recall, skip, no-show, hold/resume, transfer (if authorised), priority (reason required).
- **Ordering** Priority first, then arrival.
- **Connects to** 4.5, 4.6, 3.7, 4.8.

### 4.5 Patient called — `State` · D, R · on 4.4
- **Layout** The called patient is promoted to the top with a blue `called` chip — *Called — please proceed* — the room shown, and elapsed time since the call.
- **Primary** *Start consultation*. **Secondary** *Call again*, skip, no-show, hold.
- **Note** The board and the waiting-room screen show the same called state, so the patient is already walking when the doctor presses Start.

### 4.6 Active consultation — `State` · D · on 4.4
- **Layout** Green `in_consultation` chip, patient, elapsed timer, started-at.
- **Primary** *Complete consultation*. **Secondary** Transfer (authorised), cancel (with reason).

### 4.7 Consultation completion — `Dialog` · D · from 4.6
- **Purpose** The one place the "who controls the next call" rule lives.
- **Primary** **Complete & call next** — the recommended action.
- **Secondary** *Complete only* — completes without calling.
- **Rule** Completing must **never** auto-call. The doctor decides, every time.
- **Data** Optional notes (non-clinical), optional follow-up appointment, feedback link sent if enabled.

### 4.8 Patient history — `Page` · D · `/doctor/patients/{id}`
- **Purpose** Own patients' prior visits, where permitted.
- **Layout** Patient header → own-visit history only (visits with other doctors are not shown unless permitted) → appointment history.
- **Primary** Back to queue. **Data** Token, date, status, wait, consultation length, notes the doctor recorded.
- **States** No prior visits; not permitted (explicit, not blank).

### 4.9 Doctor's appointments — `Page` · D · `/doctor/appointments`
- **Purpose** Own booked list for today and the week ahead.
- **Layout** Day list, own only. Each row: time, patient, status, source.
- **Primary** *Start* for the patient currently in the room.
- **States** Empty day; leave days shown as unavailable with the reason.

---

# 5. Patient experience — 8 screens

Mobile-first, no account, no app install. Every one of these is public.

### 5.1 Public clinic page — `Page` · P · `/book/{slug}`
- **Purpose** The clinic's front door.
- **Layout** No app chrome. Clinic name, address, phone, hours today, and *Book an appointment*. Below: the doctors, each with specialty, fee, and next availability.
- **Primary** *Book an appointment*.
- **Data** Only what a stranger may see: name, specialty, room, fee, availability. No patient data.
- **States** Clinic suspended / booking disabled → a polite message, never a 404.
- **Responsive** Single column, 48px targets.

### 5.2 Doctor selection — `Panel` · P · on 5.1
- **Purpose** Choose who to see. **Data** Doctor, specialty, fee, next free slot, *on leave* badge where applicable.
- **States** Doctors on leave today are **not offered** rather than offered and then failing.

### 5.3 Appointment booking — `Page` · P · `/book/{slug}/{doctor}`
- **Purpose** Request a real slot.
- **Layout** Three steps: pick a date → pick a time → your details.
- **Primary** *Request appointment*. **Data** Name, phone, optional notes. No account, no password.
- **Slot rules** Only genuinely available slots are shown: doctor schedule ∩ clinic hours − holidays − doctor leave − existing bookings, within the booking window. Slots are re-validated server-side at submit.
- **States** Slot taken while the page was open → error with the next three free slots. Outside the booking window → explanation of the window.
- **Connects to** 5.4.

### 5.4 Booking confirmation — `Page` · P · `/a/{token}`
- **Purpose** Proof and a way back in.
- **Primary** *Manage my appointment* (this link *is* the manage page).
- **Content** Clinic, doctor, date/time, duration, address, a reference, and what to do on arrival. Plus *Add to calendar*.
- **Note** This page is reached from the confirmation message; the link is the credential.

### 5.5 Manage appointment — `Page` · P · `/a/{token}`
- **Purpose** Change or cancel without phoning.
- **Primary** *Show times* (reschedule). **Secondary** *Move my appointment*, *I cannot come — cancel it* (confirmed — it gives up the place).
- **States** Past appointment → read-only. Already cancelled → read-only with the reason. Inside the cancellation window → cancellation offered with the policy stated plainly.
- **Privacy** Shows nothing beyond this booking.

### 5.6 Patient token details — `Page` · P · `/t/{track-token}`
- **Purpose** "Where am I in the queue?" — the whole patient value proposition.
- **Layout** Mobile-first single column, no chrome. Big token number, doctor and room, status chip, patients ahead, estimated waiting **range**, last updated, plain-language instruction.
- **Primary** Whatever is currently useful: nothing while waiting; *I'm here* if the clinic enables it.
- **Secondary** *Move my appointment to this time*, *Cancel my visit* (confirmed), *Rate the visit* after completion.
- **Data** Own token only. **Never** another patient's name, phone, or position detail — "3 patients ahead" is a number, not a list.
- **States** Called ("Please proceed to Room 2"), on hold, completed (invites rating), cancelled, **expired** (after 18 hours, `security.track_token_hours` — renders as an expired page, not a broken one).
- **Connects to** 5.7.

### 5.7 Live queue tracking — `State` · P · on 5.6
- **Purpose** Polled position updates.
- **Behaviour** Auto-refreshes with a visible *Updated 12 seconds ago*. **Waiting-time estimates are labelled as estimates** — "about 15–20 minutes", never a promised time.
- **States** Stale → says so. Offline → says so. Never presents stale data as current.

### 5.8 QR-based token access — `Route` · P · encoded on the printed slip
- **Purpose** Get from paper to phone in one scan.
- **Behaviour** The printed slip carries a QR encoding the tracking URL (5.6). Scanning opens it directly — no typing, no search, no account.
- **Security** The token is long and unguessable, purpose-limited to that visit, and expires (18h default).

---

# 6. Clinic management — 13 screens

All owner-facing. None of this appears in the receptionist or doctor workspace.

### 6.1 Clinic profile — `Page` · O · `/settings/clinic`
- **Primary** *Edit profile* (inline full-width panel). **Data** Name, phone, email, address, city, district, timezone, currency, logo, registration/legal identifiers.
- **Connects to** 6.2, 6.4, 6.5, 6.12.

### 6.2 Doctors — `Page` · O · `/doctors`
- **Layout** Doctor cards: name, specialty, room, fee, *On leave today* badge, plus **Add leave** and **Edit**.
- **Primary** *Add doctor*. **Secondary** Add leave, edit, deactivate.
- **Data** Doctor, specialty, room, fee, queue prefix, schedule summary, leave state.
- **Note** *Add doctor & open queue* creates the doctor and starts today's queue in one action — the common case when a clinic grows.
- **Connects to** 6.3, 6.6, 6.7.

### 6.3 Doctor details — `Page` · O · `/doctors/{id}`
- **Tabs** Profile · Schedule · Leave · Queue history.
- **Primary** *Save changes*. **Data** Name, specialty, contact, room, departments, fee, queue prefix, active flag.
- **Note** Deactivating keeps history; it does not delete the doctor's queues or visits.

### 6.4 Rooms — `Page` · O · `/doctors` (rooms panel) `/settings/rooms`
- **Purpose** The physical rooms patients are called to.
- **Primary** *Add room*. **Secondary** Edit, remove, assign doctor/department, set availability.
- **Rule** A room still assigned to a doctor cannot be removed — explain why, and link to the doctor.

### 6.5 Departments — `Page` · O · `/departments`
- **Purpose** Optional grouping for larger clinics. **A one-doctor clinic never sees this as a requirement.**
- **Primary** *Add department*. **Data** Name, code, floor, description, lead doctor, active flag, **own display key**.
- **Secondary** Edit, deactivate (keeps queues and history), rotate display key (warns that screens on the old link stop updating), **today's assignments** (assign a queue or doctor).
- **Note** Assignments are what make the per-department display and the *By department* report work.

### 6.6 Working schedules — `Page` · O · `/calendar`
- **Purpose** Each doctor's recurring weekly pattern.
- **Primary** *Save schedule*. **Secondary** *Clear schedule* (confirmed — falls back to clinic default hours), copy week.
- **Data** Per doctor, per weekday: start, end, working/not working.
- **Why it matters** This is what makes online booking offer only real slots.

### 6.7 Break schedules — `Page` · O · `/breaks`
- **Primary** *Start a break* / *End break* per queue. **Secondary** Plan a recurring weekly break, remove from the plan.
- **Data** Today's breaks: queue, type, reason, length. History: started, queue, type, length, reason.
- **Behaviour** A break is shown on the board and the waiting-room screen, and **estimated waits stretch to cover it** — a queue that silently ignores a lunch break lies to patients.

### 6.8 Holidays — `Page` · O · `/calendar` (closures panel)
- **Primary** *Close a date*. **Secondary** *Reopen* (confirmed).
- **Data** Date, name, recurring annually?
- **Behaviour** Closures remove the date from booking availability and are summarised on the settings page.

### 6.9 Staff management — `Page` · O · `/settings/team`
- **Primary** *Invite a team member*. **Data** Staff accounts: name, email, role, status (Active/Disabled), last active. Pending invitations: email, role, invited, expires.
- **Secondary** Change role, disable, revoke invitation, resend.
- **States** No mail transport → **show the invitation link once** to the inviter.

### 6.10 User permissions — `Page` · O · `/settings/permissions`
- **Purpose** Delegate beyond role defaults, explicitly.
- **Layout** A matrix of capability × role, with owner-overridable exceptions.
- **Primary** *Save permissions*.
- **Rules** Clinical priority is never grantable by default to reception — granting it is a deliberate, recorded act. Everything here is enforced server-side; this screen only configures.

### 6.11 Clinic settings — `Page` · O · `/settings`
- **Tabs** Clinic · Team · Notifications · Integrations · Billing.
- **Primary** Per-tab save. **Secondary** Language & region, offline desks, data export, danger zone.
- **Danger zone** *Clear data*, behind a confirmation that requires typing the clinic name. Deletes patients, tokens and appointments; **staff accounts are kept** so the clinic is not locked out of its own account.

### 6.12 Queue settings — `Page` · O · `/queues/config`
- **Primary** *Save defaults*. **Secondary** Rename a queue, set its prefix.
- **Data** Clinic-wide defaults; per-queue prefix (Dr Perera issues `A`, Dr Silva `B`).

### 6.13 Appointment settings — `Page` · O · `/queues/appointment-policies`
- **Purpose** What happens when someone is late or cancels late.
- **Primary** *Save policy*. **Data** Clinic default + per-service overrides: lead time, cancellation window, lateness grace, slot duration, check-in window.
- **Panels** *What each service currently does* (read-only preview) and *Configured overrides*.

---

# 7. Notifications — 7 screens

### 7.1 Notification centre — `Page` · R, O, D · `/notifications`
- **Purpose** Every message the clinic sent, and whether it arrived.
- **Primary** *Mark read* / *Mark all read*. **Data** When · To · Template · Token · Status · Detail.
- **States** Failed rows show the failure reason and a **Retry**.

### 7.2 Notification templates — `Page` · O · `/settings/templates`
- **Purpose** The actual words patients read.
- **Primary** *Save template*. **Secondary** *Use the default* per message, preview, **Insert:** chips for merge fields (`{patient}`, `{token}`, `{doctor}`, `{time}`…).
- **Data** One template per event per channel, with character count (SMS is billed per segment).
- **States** Empty template → the default is used and this is stated.

### 7.3 Notification settings — `Page` · O · `/settings` (Notifications tab)
- **Primary** *Save notifications*. **Data** Three clinic-wide switches, each with a one-line explanation: **Queue alerts** (long waits, stalled queues), **System updates**, **Daily summary**.
- **Rule** Unticking stops that alert entirely. Only an owner can change these.

### 7.4 SMS integration — `Page` · O · `/settings/integrations`
- **Primary** *Connect* / *Disconnect*. **Data** Provider, sender ID, endpoint, credentials (write-only), monthly usage against allowance.
- **Behaviour** Test message button. Credentials never echoed back.

### 7.5 Email integration — `Page` · O · `/settings/integrations`
- **Data** Transport, from-name, from-address, reply-to. Test email button.

### 7.6 WhatsApp integration — `Page` · O · `/settings/integrations`
- **Data** Provider, endpoint, organisation reference, template namespace.
- **Note** WhatsApp requires pre-approved templates — the UI says so rather than letting someone write a template that will be rejected.

### 7.7 Notification delivery history — `Page` · O · `/notifications/history`
- **Data** All sends: channel, recipient (masked), event, status, attempts, provider reference, cost.
- **Primary** *Retry failed*. **States** Filter by status and channel.
- **Rule** A failed notification never blocks registration or consultation — it is queued independently and retried.

---

# 8. Reports — 7 screens

Reports must inform a small clinic without burying it. **Waiting times are always
presented as estimates.**

### 8.1 Daily clinic report — `Page` · O, R (basic) · `/reports`
- **Primary** *Export this day* (CSV) and *Print*.
- **Content** Total registered · Completed · Waiting · Skipped · No-shows · Cancelled, with arrivals by hour and outcomes over the range.
- **Data** Date picker + from/to range.

### 8.2 Patient statistics — `Page` · O · `/reports/patients`
- **Content** New vs returning, by age band, by doctor, by department. Visit frequency.

### 8.3 Doctor activity — `Page` · O · `/reports/doctors`
- **Content** Seen · Completed · Avg wait · Avg consultation · Priority count, per doctor. Session start/end and break time.

### 8.4 Queue performance — `Page` · O · `/reports/queues`
- **Content** Activity by hour, completion rate, transfer rate, hold rate, no-show rate.

### 8.5 Waiting-time reports — `Page` · O · `/reports/waiting`
- **Content** **Percentiles (p50/p90), not just the mean** — the average hides the person who waited two hours. Plus longest wait per day.
- **Rule** Labelled "estimated", always.

### 8.6 Appointment reports — `Page` · O · `/reports/appointments`
- **Content** Total · Completed · Cancelled · No-shows · Lead time distribution · Source breakdown.

### 8.7 Export reports — `Page` · O · `/settings/export`
- **Primary** *Build & download*. **Data** Pick what to include; a preview lists each file, what it holds and how many rows.
- **Rule** Patient exports are owner-only and logged in the audit trail.

---

# 9. Subscription & billing — 7 screens

### 9.1 Current subscription — `Page` · O · `/billing`
- **Content** Current plan, what it includes, renewal date, **usage against the plan** (doctors, staff, patients, tokens).
- **Primary** *Change plan*.

### 9.2 Available plans — `Page` · O · `/billing/plans`
- **Content** Plan cards with price, limits, and feature list. Current plan marked.
- **Primary** *Choose this plan* (confirmed — warns on downgrade limits).

### 9.3 Plan comparison — `Page` · O · `/billing/compare`
- **Layout** A single comparison table: capability × plan. Highlighted current column.

### 9.4 Subscription management — `Page` · O · `/billing/subscription`
- **Primary** *Change plan*, *Cancel subscription* (confirmed, with what happens next stated plainly).
- **Data** Status, renewal, seat counts, add-ons.

### 9.5 Invoices — `Page` · O · `/billing/invoices`
- **Data** Invoice · Period · Amount · Status. **Primary** *I have paid* for offline bank transfer, with *entered it wrong?* to withdraw the claim.

### 9.6 Payment history — `Page` · O · `/billing/payments`
- **Data** Date, method, amount, reference, verified/rejected.

### 9.7 Payment details — `Page` · O · `/billing/payments/{id}`
- **Data** One payment: method, reference, submitted date, verification state, linked invoice, and the rejection reason if rejected.

---

# 10. Organisation & branches — 5 screens

Only appear when the clinic belongs to a group. **A single-site clinic never
sees these.**

### 10.1 Organisation dashboard — `Page` · O · `/organisation`
- **Content** A roll-up across branches: branch, city, seen today, waiting, average wait, staff, status.
- **Rule** **No patient names and no tokens.** The difference between a useful roll-up and a privacy incident.

### 10.2 Branch list — `Page` · O · `/settings/branches`
- **Primary** *Add branch*. **Data** Name, city, address, status, staff count.

### 10.3 Branch details — `Page` · O · `/settings/branches/{id}`
- **Primary** *Save*. **Data** Profile, hours, staff, queues, status.

### 10.4 Branch management — `Page` · O · `/settings/branches/{id}/access`
- **Purpose** Who can reach which branch. **Primary** *Grant*. **Secondary** *Remove*.
- **Behaviour** The branch you are working in is switched from the header (or the drawer on mobile) — switching changes the clinic context, not the account.

### 10.5 Branch-level reporting — `Page` · O · `/reports/branches`
- **Content** The daily report sliced by branch, with a comparison column.

---

# 11. Platform administration — 12 screens

A **separate console** with its own layout and its own permissions. Never an
extension of the clinic dashboard.

### 11.1 Platform dashboard — `Page` · A · `/admin`
- **Content** Total clinics · Active · Suspended · Organisations · Branches · MRR · Subscription stats · Payment stats · *Needs attention* · *Today at a glance* · Support queue · System status.

### 11.2 Clinic management — `Page` · A · `/admin/clinics`
- **Primary** *Add clinic*. **Secondary** Search, edit, **Suspend** (no tokens until reinstated), **Reinstate**.
- **Data** Clinic, plan, status, staff, tokens today, last active.

### 11.3 Clinic details — `Page` · A · `/admin/clinics/{id}`
- **Tabs** Profile · Subscription · Usage · Staff · Audit · Support.
- **Primary** Suspend / Reinstate. **Note** Administrative viewing of clinic data is itself audited.

### 11.4 Organisation management — `Page` · A · `/admin/organisations`
- **Primary** *Create organisation*. **Secondary** Attach/detach clinics.

### 11.5 Subscription plans — `Page` · A · `/admin/plans`
- **Primary** *Create plan*. **Data** Name, price (LKR), limits (doctors, staff, branches, patients, SMS), feature list, visible/hidden.
- **Rule** Prices and limits are data, never hardcoded. Starter 3,500 / Professional 7,900 / Clinic Plus 14,900 / Enterprise (contact sales) are seed values, not constants.

### 11.6 Feature management — `Page` · A · `/admin/features`
- **Purpose** Entitlements per clinic per feature.
- **Primary** *Save entitlements*. **Data** Feature × clinic, with plan default and explicit override.
- **Rule** An override beats the plan default, and enforcement lives in the service layer — hiding a button is not enforcement.

### 11.7 Payments — `Page` · A · `/admin/payments`
- **Primary** *Verify* / *Reject* a submitted bank-transfer claim. **Data** Clinic, invoice, amount, reference, submitted date, evidence.

### 11.8 Invoices — `Page` · A · `/admin/invoices`
- **Data** All invoices across clinics: clinic, period, amount, status, payment state.

### 11.9 Support tickets — `Page` · A · `/admin/support`
- **Primary** *Reply*. **Data** Clinic, subject, status, priority, last activity, internal notes.

### 11.10 Platform activity — `Page` · A · `/admin/activity`
- **Data** Platform-wide audit: actor, action, clinic, entity, time, IP.

### 11.11 System health — `Page` · A · `/admin/health`
- **Content** The checks a monitor would run, as a page: database, notification worker, backups, schema parity, stale queues — each with severity and detail, alongside recent incidents.
- **Rule** **Show operational status only when backed by real monitoring data.** A green tick that means nothing is worse than no tick.

### 11.12 Incident management — `Page` · A, O · `/incidents`
- **Primary** *Open an incident*. **Data** Title, severity (minor/major/critical), summary.
- **Per incident** A **timeline that is append-only** — a correction is a new entry saying it corrects one, so a post-mortem cannot quietly rewrite itself. Actions: *I am on it*, *Add entry*, *Mark mitigated*, *Resolve*.
- **Ordering** Open first, then newest.

---

# 12. System screens & states — 10

These are not screens in the normal sense; they are the invariants that make the
other 92 feel finished.

### 12.1 Audit logs — `Page` · O, A · `/audit`
- **Data** When · Actor · Action · Entity · Detail · IP. Newest first, filterable by action and actor.
- **Rule** This is the artefact you produce when someone asks why a token was cancelled. Every state-changing action lands here.

### 12.2 Data export — `Page` · O · `/settings/export`
- **Primary** *Build & download*. Owner-only, logged, and previewed before generation.

### 12.3 Support centre — `Page` · R, O, D · `/support`
- **Primary** *Open a ticket*. **Data** Subject, message, and the thread with replies from both sides.
- **Rule** Reachable by **every** role — the person who notices a problem is rarely the owner.

### 12.4 Offline status & recovery — `State` + `Page` · R, O · `/settings/offline`
- **Behaviour** When the connection drops the board says so rather than pretending. A grey banner: *Offline — showing reserved numbers*.
- **Offline desk** *Set aside numbers* reserves a block of tokens; *Issue from the block* hands them out offline; *Sync now* reconciles on reconnect. Table: Queue, Next number, Set aside, Taken, Used, Left, State.
- **Rule** Offline operations never silently create conflicting tokens. Reconciliation is deliberate and visible, with conflicts listed rather than auto-resolved.
- **PWA** Installable, with a manifest and service worker.

### 12.5 Error states
- Inline and near what failed, with a Retry. A bare "Something went wrong" is not an error state.
- Server errors keep the user's input. **Never silently discard unsaved changes.**
- Queue actions that are rejected as stale explain why in the language of the board ("This patient has already been called"), never with a domain exception.

### 12.6 Empty states
- Centred icon, one-line title, one-line explanation, and **the action that fills it**.
- An empty state without a next action is a dead end.

### 12.7 Loading states
- Skeletons matching the real layout. A centred spinner is for actions, not for content that has a known shape.
- The live board shows its last known values with a stale indicator rather than blanking.

### 12.8 Confirmation dialogs
- Every destructive action is confirmed. **High-cost ones require typing the clinic name.**
- Cancel-then-Confirm ordering, primary on the right, Escape cancels without submitting.
- **No native `confirm()`/`prompt()`/`alert()` anywhere** — unstyleable, blockable, and they break keyboard flow.

### 12.9 Permission-denied states
- A real page explaining **who can** do it, with a route to request access. Never a bare 403, never a blank screen, never a silently hidden feature with no explanation.

### 12.10 Responsive mobile layouts
- Every screen has a defined phone (<576), tablet (768–1023) and desktop (≥1024) layout.
- Doctor controls are 48px minimum. Tables become stacked cards, never horizontal scrollers.
- The bottom tab bar carries the role's three or four primary destinations.

---

## Public displays & kiosk (no chrome)

### D1 Waiting-room TV display — `Route` · public · `/display/{key}`
- Big tokens, big names. Depending on how many doctors are working: equal cards per doctor, or one solo panel with an up-next list. A called room is **ringed, not enlarged** — enlargement makes the board reflow and people lose their place.
- **Controls (staff preview only)** Full screen, voice announcements on/off, **Rotate key** (confirmed — every screen on the old link stops updating), self-check-in kiosk setup with its own URL, *Switch off*.
- **Amber banner** across the top when the connection is lost. Never shows a patient's full name abroad — first name and initial only, per clinic policy.

### D2 Department display — `Route` · public · `/display/{key}?department=`
Same, filtered to one department using that department's key.

### D3 Public queue board — `Route` · public · `/board/{key}`, `/live/{key}`
Read-only, no sign-in. A **Where do I stand?** lookup (`/board/{key}/position`) accepts a token and returns **only** a position number — no name, no phone, nothing that lets one patient identify another.

### D4 Kiosk check-in — `Route` · public · `/kiosk/{key}`
A tablet on a stand. One short form: full name, phone, which queue. **Get my number** issues a real token into the same queue and shows a printable slip. No navigation, no chrome, 56px controls.

---

## Counts

| Module | Screens |
|---|---:|
| Authentication | 5 |
| Clinic onboarding | 7 |
| Receptionist | 12 |
| Doctor workspace | 9 |
| Patient experience | 8 |
| Clinic management | 13 |
| Notifications | 7 |
| Reports | 7 |
| Subscription & billing | 7 |
| Organisation & branches | 5 |
| Platform administration | 12 |
| System screens & states | 10 |
| Public displays & kiosk | 4 |
| **Total** | **106** |
