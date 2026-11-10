<?php
declare(strict_types=1);

/**
 * Route table. Every POST is CSRF-checked by default and every protected
 * route declares its ability, so authorisation never lives in the view layer.
 */

use App\Core\Router;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminOrganisationController;
use App\Http\Controllers\AdminBillingController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ClinicController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\DisplayController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\AppointmentPolicyController;
use App\Http\Controllers\BreakController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PriorityRuleController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\QueueConfigController;
use App\Http\Controllers\RoomController;

/* --------------------------------------------------------------- public */
Router::get('/', [PublicController::class, 'landing']);
Router::get('/features', [PublicController::class, 'features']);
Router::get('/pricing', [PublicController::class, 'pricing']);
Router::get('/register', [PublicController::class, 'registerForm']);
Router::post('/register', [PublicController::class, 'register']);
Router::get('/t/{token}', [PublicController::class, 'track'], ['csrf' => false]);
// Patient self-service cancellation. CSRF applies: the tracking page renders a
// token, so a cross-site link cannot cancel somebody's visit.
Router::post('/t/{token}/cancel', [PublicController::class, 'cancelTracked']);
Router::post('/t/{token}/reschedule', [PublicController::class, 'rescheduleTracked']);
Router::post('/t/{token}/rate', [PublicController::class, 'rateVisit']);
Router::get('/demo/board', [PublicController::class, 'demoBoard']);
Router::get('/manifest.webmanifest', [PublicController::class, 'manifest']);
Router::get('/sw.js', [PublicController::class, 'serviceWorker']);

/* ----------------------------------------------------------------- auth */
Router::get('/login', [AuthController::class, 'showLogin']);
Router::post('/login', [AuthController::class, 'login'], ['csrf' => false]);
// Step-up (second factor). Public by necessity: the session is deliberately
// not authenticated until these succeed.
Router::get('/login/2fa', [AuthController::class, 'showTwoFactor']);
Router::post('/login/2fa', [AuthController::class, 'submitTwoFactor']);

// Password recovery. Public by necessity; rate limited per IP inside the
// controller and single-use per token. See PasswordResetService.
Router::get('/forgot-password', [PasswordResetController::class, 'showRequest']);
Router::post('/forgot-password', [PasswordResetController::class, 'send']);
Router::get('/reset-password/{token}', [PasswordResetController::class, 'showReset']);
Router::post('/reset-password/{token}', [PasswordResetController::class, 'reset']);
// Email confirmation. The link is public; resending needs the session.
Router::get('/verify-email/{token}', [EmailVerificationController::class, 'verify'], ['csrf' => false]);
Router::post('/verify-email/resend', [EmailVerificationController::class, 'resend'], ['auth' => true]);
Router::post('/admin/clinics/{id}/verify-email', [EmailVerificationController::class, 'verifyByHand'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/login/2fa/cancel', [AuthController::class, 'cancelTwoFactor']);
// Staff invitation acceptance
Router::get('/invite/{token}', [AuthController::class, 'showInvite'], ['csrf' => false]);
Router::post('/invite/{token}', [AuthController::class, 'acceptInvite']);
// Account security (2FA enrolment) — any signed-in user
Router::get('/settings/security', [AuthController::class, 'security'], ['auth' => true]);
Router::post('/settings/security/2fa/begin', [AuthController::class, 'beginTwoFactor'], ['auth' => true]);
Router::post('/settings/security/2fa/confirm', [AuthController::class, 'confirmTwoFactor'], ['auth' => true]);
Router::post('/settings/security/2fa/disable', [AuthController::class, 'disableTwoFactor'], ['auth' => true]);
Router::post('/logout', [AuthController::class, 'logout'], ['auth' => true]);
Router::post('/locale', [AuthController::class, 'locale']);

/* ------------------------------------------------------------ reception */
Router::get('/app', [DashboardController::class, 'index'], ['auth' => true, 'ability' => 'queue.operate']);

/* The doctor workspace. Kept separate from the reception dashboard on
   purpose: the PDF asks for a dedicated, minimal doctor experience with its
   own navigation, not the clinic board with a few links hidden. */
Router::get('/doctor', [DoctorController::class, 'today'], ['auth' => true, 'ability' => 'queue.consult']);
Router::get('/doctor/schedule', [DoctorController::class, 'schedule'], ['auth' => true, 'ability' => 'queue.consult']);
Router::get('/doctor/patients', [DoctorController::class, 'patients'], ['auth' => true, 'ability' => 'queue.consult']);
Router::get('/doctor/profile', [DoctorController::class, 'profile'], ['auth' => true, 'ability' => 'queue.consult']);
Router::get('/queue/new', [QueueController::class, 'newPatient'], ['auth' => true, 'ability' => 'queue.issue']);
Router::post('/queue/new', [QueueController::class, 'createEntry'], ['auth' => true, 'ability' => 'queue.issue']);
Router::get('/queue', [QueueController::class, 'show'], ['auth' => true, 'ability' => 'queue.operate']);
// A barcode reader types the code and presses Enter: GET, no CSRF, no mutation.
Router::get('/queue/scan', [QueueController::class, 'scan'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/queue/token-issued', [QueueController::class, 'tokenIssued'], ['auth' => true, 'ability' => 'queue.operate']);
// Token slip for a thermal printer. HTML for a browser print dialog, ESC/POS
// for a wired receipt printer. Both carry only the token — no clinical data.
Router::get('/queue/{id}/slip', [QueueController::class, 'slip'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/queue/{id}/slip.escpos', [QueueController::class, 'slipEscpos'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/queue/{id}/qr.png', [QueueController::class, 'slipQr'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/queue/entry', [QueueController::class, 'entry'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/queue/action', [QueueController::class, 'action'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/queue/step', [QueueController::class, 'markStep'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/queue/session/start', [QueueController::class, 'startSession'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/queue/session', [QueueController::class, 'sessionControl'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/queue/delay', [QueueController::class, 'announceDelay'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/display', [DisplayController::class, 'screen'], ['auth' => true]);
Router::get('/settings/displays', [DisplayController::class, 'displays'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/display/rotate', [DisplayController::class, 'rotate'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/display/keys', [DisplayController::class, 'issueKey'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/display/keys/{id}/revoke', [DisplayController::class, 'revokeKey'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/display/kiosk', [DisplayController::class, 'kioskIssuing'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/display/kiosk/off', [DisplayController::class, 'kioskRevoke'], ['auth' => true, 'ability' => 'clinic.manage']);

/* ------------------------------------------------------- patients, appts */
Router::get('/patients', [PatientController::class, 'index'], ['auth' => true, 'ability' => 'patient.manage']);
Router::get('/patients/{id}', [PatientController::class, 'show'], ['auth' => true, 'ability' => 'patient.manage']);
Router::post('/patients/{id}', [PatientController::class, 'update'], ['auth' => true, 'ability' => 'patient.manage']);

Router::get('/appointments', [AppointmentController::class, 'index'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/appointments', [AppointmentController::class, 'store'], ['auth' => true, 'ability' => 'appointment.manage']);
Router::post('/appointments/{id}/checkin', [AppointmentController::class, 'checkin'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/appointments/cancel-day', [AppointmentController::class, 'cancelDay'], ['auth' => true, 'ability' => 'appointment.cancel_day']);

/* ------------------------------------------------- doctors, rooms, staff */
Router::get('/doctors', [ClinicController::class, 'doctors'], ['auth' => true]);
Router::post('/doctors', [ClinicController::class, 'storeDoctor'], ['auth' => true, 'ability' => 'doctor.manage']);
// Rooms. Previously created only by signup and never editable.
// Queue settings: the pattern tomorrow's queues are created from.
// Breaks: operational start/end, plus the weekly plan an owner sets.
Router::get('/breaks', [BreakController::class, 'index'], ['auth' => true]);
Router::post('/breaks/start', [BreakController::class, 'start'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/breaks/end', [BreakController::class, 'end'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/breaks/schedule', [BreakController::class, 'schedule'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/breaks/schedule/{id}/remove', [BreakController::class, 'unschedule'], ['auth' => true, 'ability' => 'clinic.manage']);

Router::get('/queues/config', [QueueConfigController::class, 'index'], ['auth' => true]);
Router::post('/queues/config', [QueueConfigController::class, 'save'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/queues/{id}/rename', [QueueConfigController::class, 'rename'], ['auth' => true, 'ability' => 'clinic.manage']);

// Priority rules — the standing clinical decisions the desk used to retype.
Router::get('/queues/priority-rules', [PriorityRuleController::class, 'index'], ['auth' => true]);
Router::post('/queues/priority-rules', [PriorityRuleController::class, 'save'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/queues/priority-rules/{id}/toggle', [PriorityRuleController::class, 'toggle'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/queues/priority-rules/{id}/delete', [PriorityRuleController::class, 'delete'], ['auth' => true, 'ability' => 'clinic.manage']);

// Service workflows — the steps a visit must pass before it is complete.
Router::get('/queues/workflows', [WorkflowController::class, 'index'], ['auth' => true]);
Router::post('/queues/workflows', [WorkflowController::class, 'save'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/queues/workflows/{id}/delete', [WorkflowController::class, 'delete'], ['auth' => true, 'ability' => 'clinic.manage']);

// Getting started — a checklist measured from clinic data, not ticks.
Router::get('/getting-started', [OnboardingController::class, 'index'], ['auth' => true]);
Router::get('/getting-started/display', [OnboardingController::class, 'seenDisplay'], ['auth' => true]);

// Appointment policies — per-service grace, horizon and walk-in acceptance.
Router::get('/queues/appointment-policies', [AppointmentPolicyController::class, 'index'], ['auth' => true]);
Router::post('/queues/appointment-policies', [AppointmentPolicyController::class, 'save'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/queues/appointment-policies/{id}/delete', [AppointmentPolicyController::class, 'delete'], ['auth' => true, 'ability' => 'clinic.manage']);

// Incident records — an append-only timeline of what broke and what was done.
Router::get('/incidents', [IncidentController::class, 'index'], ['auth' => true]);
Router::post('/incidents', [IncidentController::class, 'openIncident'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::get('/incidents/{id}', [IncidentController::class, 'show'], ['auth' => true]);
Router::post('/incidents/{id}/entries', [IncidentController::class, 'addEntry'], ['auth' => true]);
Router::post('/incidents/{id}/acknowledge', [IncidentController::class, 'acknowledge'], ['auth' => true]);
Router::post('/incidents/{id}/mitigate', [IncidentController::class, 'mitigate'], ['auth' => true]);
Router::post('/incidents/{id}/resolve', [IncidentController::class, 'resolve'], ['auth' => true]);

Router::get('/rooms', [RoomController::class, 'index'], ['auth' => true]);
Router::post('/rooms', [RoomController::class, 'store'], ['auth' => true, 'ability' => 'doctor.manage']);
Router::post('/rooms/{id}', [RoomController::class, 'update'], ['auth' => true, 'ability' => 'doctor.manage']);
Router::post('/rooms/{id}/delete', [RoomController::class, 'destroy'], ['auth' => true, 'ability' => 'doctor.manage']);
// Leave routes are declared BEFORE `/doctors/{id}`: the router takes the
// first match, so a literal path must never sit behind a parameter route.
Router::post('/doctors/leave', [ClinicController::class, 'addLeave'], ['auth' => true, 'ability' => 'doctor.manage']);
Router::post('/doctors/leave/{id}/delete', [ClinicController::class, 'removeLeave'], ['auth' => true, 'ability' => 'doctor.manage']);
Router::post('/doctors/{id}', [ClinicController::class, 'updateDoctor'], ['auth' => true, 'ability' => 'doctor.manage']);
// Departments. `clinic.manage` gates writes; reads are open to any staff
// member, because knowing which department you are standing in is not a
// privilege.
Router::get('/departments', [DepartmentController::class, 'index'], ['auth' => true]);
Router::post('/departments', [DepartmentController::class, 'store'], ['auth' => true, 'ability' => 'department.manage']);
Router::post('/departments/assign', [DepartmentController::class, 'assign'], ['auth' => true, 'ability' => 'department.manage']);
Router::get('/departments/{id}', [DepartmentController::class, 'show'], ['auth' => true]);
Router::post('/departments/{id}', [DepartmentController::class, 'update'], ['auth' => true, 'ability' => 'department.manage']);
Router::post('/departments/{id}/deactivate', [DepartmentController::class, 'deactivate'], ['auth' => true, 'ability' => 'department.manage']);
Router::post('/departments/{id}/display-key', [DepartmentController::class, 'issueDisplayKey'], ['auth' => true, 'ability' => 'department.manage']);

// Operating calendar: per-doctor weekly schedules and clinic closure days.
Router::get('/calendar', [CalendarController::class, 'index'], ['auth' => true]);
Router::post('/calendar/schedule', [CalendarController::class, 'saveSchedule'], ['auth' => true, 'ability' => 'doctor.manage']);
Router::post('/calendar/schedule/clear', [CalendarController::class, 'clearSchedule'], ['auth' => true, 'ability' => 'doctor.manage']);
Router::post('/calendar/holidays', [CalendarController::class, 'addHoliday'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/calendar/holidays/{id}/delete', [CalendarController::class, 'removeHoliday'], ['auth' => true, 'ability' => 'clinic.manage']);

// Support tickets.
Router::get('/support', [SupportController::class, 'index'], ['auth' => true]);
Router::post('/support', [SupportController::class, 'store'], ['auth' => true]);
Router::get('/support/{id}', [SupportController::class, 'show'], ['auth' => true]);
Router::post('/support/{id}/reply', [SupportController::class, 'reply'], ['auth' => true]);

// Staff notification inbox.
Router::get('/notifications', [NotificationController::class, 'index'], ['auth' => true]);
Router::post('/notifications/{id}/read', [NotificationController::class, 'markRead'], ['auth' => true]);
Router::post('/notifications/read-all', [NotificationController::class, 'markAllRead'], ['auth' => true]);

Router::get('/settings', [ClinicController::class, 'settings'], ['auth' => true]);
Router::post('/settings', [ClinicController::class, 'saveSettings'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/settings/hours', [ClinicController::class, 'saveHours'], ['auth' => true, 'ability' => 'clinic.manage']);
// A clinic closing its own account. Requires typing the clinic name; starts a
// 90-day retention window rather than deleting anything on the spot.
Router::post('/settings/closure', [AdminController::class, 'requestClosure'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/settings/staff', [ClinicController::class, 'storeStaff'], ['auth' => true, 'ability' => 'staff.manage']);
Router::post('/settings/invites', [ClinicController::class, 'inviteStaff'], ['auth' => true, 'ability' => 'staff.manage']);
Router::post('/settings/invites/{id}/revoke', [ClinicController::class, 'revokeInvite'], ['auth' => true, 'ability' => 'staff.manage']);
Router::get('/settings/templates', [ClinicController::class, 'templates'], ['auth' => true]);
Router::get('/settings/integrations', [ClinicController::class, 'integrations'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/settings/integrations/connect', [ClinicController::class, 'connectIntegration'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/settings/integrations/disconnect', [ClinicController::class, 'disconnectIntegration'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/settings/templates', [ClinicController::class, 'saveTemplate'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/settings/templates/reset', [ClinicController::class, 'resetTemplate'], ['auth' => true, 'ability' => 'clinic.manage']);

// Data portability: the whole clinic in one archive. Both are POST-or-GET by
// design — the page explains, the download is a deliberate act that
// re-authorises and streams, so an archive cannot be left sitting behind a
// bookmark after the session ends.
Router::get('/settings/export', [ClinicController::class, 'export'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/settings/export', [ClinicController::class, 'downloadExport'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::get('/settings/branches', [ClinicController::class, 'branches'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/settings/branches', [ClinicController::class, 'grantBranch'], ['auth' => true, 'ability' => 'staff.manage']);
Router::post('/settings/branches/revoke', [ClinicController::class, 'revokeBranch'], ['auth' => true, 'ability' => 'staff.manage']);
// The switcher: any signed-in user with more than one branch.
Router::post('/branch/switch', [ClinicController::class, 'switchBranch'], ['auth' => true]);
Router::get('/organisation', [ClinicController::class, 'organisation'], ['auth' => true, 'ability' => 'clinic.manage']);

/* --------------------------------------------------- reports and audits */
Router::get('/reports', [ReportController::class, 'index'], ['auth' => true, 'ability' => 'reports.view']);
Router::get('/reports/export', [ReportController::class, 'export'], ['auth' => true, 'ability' => 'reports.view']);
Router::get('/audit', [ReportController::class, 'audit'], ['auth' => true, 'ability' => 'clinic.manage']);

/* -------------------------------------------------------------- billing */
Router::get('/billing', [BillingController::class, 'index'], ['auth' => true]);
Router::post('/billing/plan', [BillingController::class, 'changePlan'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/billing/pay', [BillingController::class, 'pay'], ['auth' => true, 'ability' => 'billing.view']);
Router::post('/billing/withdraw-claim', [BillingController::class, 'withdrawClaim'], ['auth' => true, 'ability' => 'billing.view']);

/* ------------------------------------------------------ platform admin */
Router::get('/admin', [AdminController::class, 'index'], ['auth' => true, 'roles' => ['super_admin']]);
Router::get('/admin/clinics', [AdminController::class, 'clinics'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/clinics/{id}', [AdminController::class, 'updateClinic'], ['auth' => true, 'roles' => ['super_admin']]);
// Suspension stops new tokens without deleting anything or signing staff out.
Router::post('/admin/clinics/{id}/suspend', [AdminController::class, 'suspendClinic'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/clinics/{id}/reinstate', [AdminController::class, 'reinstateClinic'], ['auth' => true, 'roles' => ['super_admin']]);
Router::get('/admin/plans', [AdminController::class, 'plans'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/plans', [AdminController::class, 'savePlan'], ['auth' => true, 'roles' => ['super_admin']]);

// Payment verification. A clinic's claim is only money-in-the-bank once a
// platform administrator matches it; this is that screen.
Router::get('/admin/billing', [AdminBillingController::class, 'index'], ['auth' => true, 'roles' => ['super_admin']]);
// The platform side of support. Separate paths from the clinic view: a super
// admin has no clinic, and sharing one path would mean every method guarding
// against its own audience.
// Groups: the screen that creates an organisation, which /organisation reads.
Router::get('/admin/organisations', [AdminOrganisationController::class, 'index'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/organisations', [AdminOrganisationController::class, 'store'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/organisations/{id}/attach', [AdminOrganisationController::class, 'attach'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/organisations/{id}/detach', [AdminOrganisationController::class, 'detach'], ['auth' => true, 'roles' => ['super_admin']]);

Router::get('/admin/support', [SupportController::class, 'index'], ['auth' => true, 'roles' => ['super_admin']]);
Router::get('/admin/support/{id}', [SupportController::class, 'show'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/support/{id}/reply', [SupportController::class, 'reply'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/support/{id}/status', [SupportController::class, 'setStatus'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/billing/{id}/verify', [AdminBillingController::class, 'verify'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/billing/{id}/reject', [AdminBillingController::class, 'reject'], ['auth' => true, 'roles' => ['super_admin']]);
Router::get('/admin/features', [AdminController::class, 'features'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/features/{clinicId}', [AdminController::class, 'saveFeature'], ['auth' => true, 'roles' => ['super_admin'], 'csrf' => false]);
Router::post('/admin/features/{clinicId}/unset', [AdminController::class, 'unsetFeature'], ['auth' => true, 'roles' => ['super_admin'], 'csrf' => false]);

/* ------------------------------------------------------------- JSON API */
Router::get('/api/queue/board', [ApiController::class, 'board'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/api/queue/action', [ApiController::class, 'action'], ['auth' => true, 'ability' => 'queue.operate']);
// Offline writes. A block of ticket numbers is set aside while the connection
// is up; the desk issues from it without a connection and replays the
// reservations on reconnect. Both need queue.issue — the same permission as
// the desk form — because that is what they are doing.
Router::post('/api/queue/offline/block', [ApiController::class, 'reserveOfflineBlock'], ['auth' => true]);
Router::post('/api/queue/offline/sync', [ApiController::class, 'reconcileOffline'], ['auth' => true]);
Router::get('/settings/offline', [ClinicController::class, 'offline'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/api/track/{token}', [ApiController::class, 'track']);
Router::get('/api/display/{key}', [ApiController::class, 'display']);
Router::get('/board/{key}', [DisplayController::class, 'publicScreen']);
// Read-only "where do I stand?" for one patient. Keyed like the board so it
// needs no sign-in and inherits the same revocation, and declared next to it
// so nothing broader can shadow the {key} segment.
Router::get('/board/{key}/position', [DisplayController::class, 'position']);
Router::get('/live/{key}', [DisplayController::class, 'live']);

// Online booking: 404 for clinics that have not opted in (see PublicController).
Router::get('/book/{slug}', [PublicController::class, 'bookForm']);
Router::post('/book/{slug}', [PublicController::class, 'book']);
// The waiting-room kiosk. The 48-hex segment is the credential (see
// KioskService); CSRF stays on, because the screen is a public terminal and a
// page on the same network must not be able to mint tokens through it.
Router::get('/kiosk/{key}', [KioskController::class, 'show'], ['csrf' => false]);
Router::post('/kiosk/{key}', [KioskController::class, 'issue']);
// A booked patient's own link, signed over the id and the current time (see
// AppointmentService::manageUrl). No login: the signature is the credential.
Router::get('/a/{token}', [PublicController::class, 'manageAppointment'], ['csrf' => false]);
Router::post('/a/{token}/reschedule', [PublicController::class, 'rescheduleManaged']);
Router::post('/a/{token}/cancel', [PublicController::class, 'cancelManaged']);
Router::get('/api/summary', [ApiController::class, 'summary'], ['auth' => true]);

// Health endpoint for an external monitor. Authorisation is inside the
// controller (bearer token or super admin) because a "signed-in" probe is not
// how uptime monitors work.
Router::get('/api/health', [MonitorController::class, 'show'], ['csrf' => false]);
