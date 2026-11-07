<?php
declare(strict_types=1);

/**
 * QueuePilot configuration.
 * Values come from environment variables so the same code runs locally,
 * on shared hosting or on a dedicated server without edits.
 */
return [
    'app' => [
        'name'     => 'QueuePilot',
        'tagline'  => 'Live queue management for clinics',
        'env'      => getenv('APP_ENV') ?: 'production',
        'debug'    => (getenv('APP_DEBUG') ?: '0') === '1',
        'url'      => getenv('APP_URL') ?: 'http://localhost:8080',
        'key'      => getenv('APP_KEY') ?: 'dev-only-key-change-me-in-env-8f3a1c9d',
        'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Colombo',
        'locales'  => ['en' => 'English', 'si' => 'සිංහල', 'ta' => 'தமிழ்'],
        'default_locale' => 'en',
    ],
    'db' => [
        // mysql | sqlite  — same schema, same PHP layer.
        'driver'   => getenv('DB_DRIVER') ?: 'sqlite',
        'sqlite'   => getenv('DB_SQLITE') ?: __DIR__ . '/../storage/db/queuepilot.sqlite',
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_DATABASE') ?: 'queuepilot',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset'  => 'utf8mb4',
    ],
    'security' => [
        // DEVTEST: one cookie per port. Cookies are scoped by host, NOT by port,
        // so five ports sharing 'qp_session' would fight over one session and
        // lose each other's locale/flash/CSRF state.
        'session_name'        => 'qp_devtest_' . (getenv('QP_PORT') ?: 'cli'),
        // File-backed sessions in a directory we control. Native PHP hosts can
        // point this at /var/lib/php/sessions; the WASM runtime cannot write
        // outside the project, so storage/sessions is the portable choice.
        'session_path'        => getenv('SESSION_PATH') ?: (__DIR__ . '/../storage/sessions/' . (getenv('QP_PORT') ?: 'cli')),
        'session_lifetime'    => 60 * 60 * 8,
        'idle_timeout'        => 60 * 45,
        'password_min'        => 10,
        'login_max_attempts'  => 6,
        'login_window_min'    => 10,
        'track_token_bytes'   => 24,
        'track_token_hours'   => 18,
        // Step-up authentication and invitations
        'totp_max_attempts'   => 5,
        'invite_hours'        => 72,
        // Rate limits that protect public surfaces (per minute unless stated)
        'display_key_per_min' => 90,
        'display_key_per_day' => 20000,
        'track_token_per_min' => 30,
        'booking_per_min'     => 8,
        'api_action_per_min'  => 120,
    ],
    'monitor' => [
        // Bearer token for GET /api/health. Empty => endpoint is closed to
        // anonymous callers entirely.
        'token' => getenv('MONITOR_TOKEN') ?: '',
    ],
    'backup' => [
        // A backup older than this is treated as a failure, not a nuisance.
        'max_age_hours' => (int) (getenv('BACKUP_MAX_AGE_HOURS') ?: 48),
    ],
    'mail' => [
        // none | log | smtp — "none" means invitations are shown to the admin
        // to pass on by hand rather than silently pretending they were sent.
        'transport'  => getenv('MAIL_TRANSPORT') ?: 'none',
        'host'       => getenv('MAIL_HOST') ?: '',
        'port'       => (int) (getenv('MAIL_PORT') ?: 587),
        'username'   => getenv('MAIL_USERNAME') ?: '',
        'password'   => getenv('MAIL_PASSWORD') ?: '',
        'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls',
        'from'       => getenv('MAIL_FROM') ?: 'no-reply@queuepilot.local',
    ],
    'sms' => [
        // log | http | auto. "log" exercises the whole pipeline and writes the
        // message to the application log so a fresh install cannot text a real
        // patient; "http" posts to the aggregator and fails loudly when the
        // credentials are missing; "auto" uses http when they are present.
        'gateway'        => getenv('SMS_GATEWAY') ?: 'log',
        'provider_url'   => getenv('SMS_PROVIDER_URL') ?: '',
        'provider_sid'   => getenv('SMS_PROVIDER_SID') ?: '',
        'provider_token' => getenv('SMS_PROVIDER_TOKEN') ?: '',
        'from'           => getenv('SMS_FROM') ?: 'QueuePilot',
    ],
    'queue' => [
        // default policy; clinics can override per-workspace
        'call_timeout_minutes' => 5,
        'max_recalls'          => 3,
        'estimate_min_confidence' => 4,
    ],
];
