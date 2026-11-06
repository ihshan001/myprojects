<?php
declare(strict_types=1);

/**
 * Front controller bootstrap: autoloader, config, error handling, DB, router.
 * Works both under a normal PHP SAPI and under the WASM runtime.
 */
define('QP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = QP_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Load .env before the config file reads the environment. Real environment
// variables always take precedence (see EnvFile).
App\Core\EnvFile::load(QP_ROOT . '/.env');

$config = require QP_ROOT . '/config/config.php';
date_default_timezone_set($config['app']['timezone']);
mb_internal_encoding('UTF-8');

App\Core\Env::init($config);

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

App\Core\Log::boot(QP_ROOT . '/storage/logs');
require QP_ROOT . '/app/helpers.php';
App\Core\Db::init($config['db']);

return $config;
