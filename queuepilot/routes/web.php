<?php
declare(strict_types=1);

/**
 * Route table. Every POST is CSRF-checked by default and every protected
 * route declares its ability, so authorisation never lives in the view layer.
 */

use App\Core\Router;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ClinicController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DisplayController;
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
Router::get('/demo/board', [PublicController::class, 'demoBoard']);
Router::get('/manifest.webmanifest', [PublicController::class, 'manifest']);
Router::get('/sw.js', [PublicController::class, 'serviceWorker']);

/* ----------------------------------------------------------------- auth */
Router::get('/login', [AuthController::class, 'showLogin']);
Router::post('/login', [AuthController::class, 'login']);
Router::post('/logout', [AuthController::class, 'logout'], ['auth' => true]);
Router::post('/locale', [AuthController::class, 'locale']);

/* ------------------------------------------------------------ reception */
Router::get('/app', [DashboardController::class, 'index'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/queue/new', [QueueController::class, 'newPatient'], ['auth' => true, 'ability' => 'queue.issue']);
Router::post('/queue/new', [QueueController::class, 'createEntry'], ['auth' => true, 'ability' => 'queue.issue']);
Router::get('/queue', [QueueController::class, 'show'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/queue/entry', [QueueController::class, 'entry'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/queue/action', [QueueController::class, 'action'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/queue/session', [QueueController::class, 'sessionControl'], ['auth' => true, 'ability' => 'queue.operate']);
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
Router::post('/doctors/{id}', [ClinicController::class, 'updateDoctor'], ['auth' => true, 'ability' => 'doctor.manage']);
Router::get('/settings', [ClinicController::class, 'settings'], ['auth' => true]);
Router::post('/settings', [ClinicController::class, 'saveSettings'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/settings/staff', [ClinicController::class, 'storeStaff'], ['auth' => true, 'ability' => 'staff.manage']);

/* --------------------------------------------------- reports and audits */
Router::get('/reports', [ReportController::class, 'index'], ['auth' => true, 'ability' => 'reports.view']);
Router::get('/reports/export', [ReportController::class, 'export'], ['auth' => true, 'ability' => 'reports.view']);
Router::get('/audit', [ReportController::class, 'audit'], ['auth' => true, 'ability' => 'clinic.manage']);

/* -------------------------------------------------------------- billing */
Router::get('/billing', [BillingController::class, 'index'], ['auth' => true]);
Router::post('/billing/plan', [BillingController::class, 'changePlan'], ['auth' => true, 'ability' => 'clinic.manage']);
Router::post('/billing/pay', [BillingController::class, 'pay'], ['auth' => true, 'ability' => 'billing.view']);

/* ------------------------------------------------------ platform admin */
Router::get('/admin', [AdminController::class, 'index'], ['auth' => true, 'roles' => ['super_admin']]);
Router::get('/admin/clinics', [AdminController::class, 'clinics'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/clinics/{id}', [AdminController::class, 'updateClinic'], ['auth' => true, 'roles' => ['super_admin']]);
Router::get('/admin/plans', [AdminController::class, 'plans'], ['auth' => true, 'roles' => ['super_admin']]);
Router::post('/admin/plans', [AdminController::class, 'savePlan'], ['auth' => true, 'roles' => ['super_admin']]);

/* ------------------------------------------------------------- JSON API */
Router::get('/api/queue/board', [ApiController::class, 'board'], ['auth' => true, 'ability' => 'queue.operate']);
Router::post('/api/queue/action', [ApiController::class, 'action'], ['auth' => true, 'ability' => 'queue.operate']);
Router::get('/api/track/{token}', [ApiController::class, 'track']);
Router::get('/api/display/{key}', [ApiController::class, 'display']);
Router::get('/board/{key}', [DisplayController::class, 'publicScreen']);
Router::get('/api/summary', [ApiController::class, 'summary'], ['auth' => true]);
