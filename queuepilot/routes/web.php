<?php
declare(strict_types=1);

/**
 * Route table. Every POST is CSRF-checked by default and every protected
 * route declares its ability, so authorisation never lives in the view layer.
 */

use App\Core\Router;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminBillingController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ClinicController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisplayController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\ReportController;

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
Router::get('/demo/board', [PublicController::class, 'demoBoard']);
Router::get('/manifest.webmanifest', [PublicController::class, 'manifest']);
Router::get('/sw.js', [PublicController::class, 'serviceWorker']);

/* ----------------------------------------------------------------- auth */
Router::get('/login', [AuthController::class, 'showLogin']);
Router::post('/login', [AuthController::class, 'login']);
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
Router::get('/queue/new', [QueueController::class, 'newPatient'], ['auth' => true, 'ability' => 'queue.issue']);
Router::post('/queue/new', [QueueController::class, 'createEntry'], ['auth' => true, 'ability' => 'queue.issue']);
Router::get('/queue', [QueueController::class, 'show'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/queue/token-issued', [QueueController::class, 'tokenIssued'], ['auth' => true, 'ability' => 'queue.operate']);
// Token slip for a thermal printer. HTML for a browser print dialog, ESC/POS
// for a wired receipt printer. Both carry only the token — no clinical data.
Router::get('/queue/{id}/slip', [QueueController::class, 'slip'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/queue/{id}/slip.escpos', [QueueController::class, 'slipEscpos'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/queue/entry', [QueueController::class, 'entry'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/queue/action', [QueueController::class, 'action'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/queue/session', [QueueController::class, 'sessionControl'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/queue/delay', [QueueController::class, 'announceDelay'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/display', [DisplayController::class, 'screen'], ['auth' => true]);

/* ------------------------------------------------------- patients, appts */
Router::get('/patients', [PatientController::class, 'index'], ['auth' => true, 'ability' => 'patient.manage']);
Router::get('/patients/{id}', [PatientController::class, 'show'], ['auth' => true, 'ability' => 'patient.manage']);
Router::post('/patients/{id}', [PatientController::class, 'update'], ['auth' => true, 'ability' => 'patient.manage']);

Router::get('/appointments', [AppointmentController::class, 'index'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/appointments', [AppointmentController::class, 'store'], ['auth' => true, 'ability' => 'appointment.manage']);
Router::post('/appointments/{id}/checkin', [AppointmentController::class, 'checkin'], ['auth' => true, 'ability' => 'queue.operate']);

/* ------------------------------------------------- doctors, rooms, staff */
Router::get('/doctors', [ClinicController::class, 'doctors'], ['auth' => true]);
Router::post('/doctors', [ClinicController::class, 'storeDoctor'], ['auth' => true, 'ability' => 'doctor.manage']);
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
Router::post('/admin/billing/{id}/verify', [AdminBillingController::class, 'verify'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/billing/{id}/reject', [AdminBillingController::class, 'reject'], ['auth' => true, 'roles' => ['super_admin']]);

/* ------------------------------------------------------------- JSON API */
Router::get('/api/queue/board', [ApiController::class, 'board'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/api/queue/action', [ApiController::class, 'action'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/api/track/{token}', [ApiController::class, 'track']);
Router::get('/api/display/{key}', [ApiController::class, 'display']);
Router::get('/board/{key}', [DisplayController::class, 'publicScreen']);

// Online booking: 404 for clinics that have not opted in (see PublicController).
Router::get('/book/{slug}', [PublicController::class, 'bookForm']);
Router::post('/book/{slug}', [PublicController::class, 'book']);
Router::get('/api/summary', [ApiController::class, 'summary'], ['auth' => true]);

// Health endpoint for an external monitor. Authorisation is inside the
// controller (bearer token or super admin) because a "signed-in" probe is not
// how uptime monitors work.
Router::get('/api/health', [MonitorController::class, 'show'], ['csrf' => false]);
