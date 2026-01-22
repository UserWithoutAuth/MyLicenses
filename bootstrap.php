<?php
/**
 * Application Bootstrap
 *
 * Initializes the application core components
 *
 * @package LicenseServer
 */

// Security check
if (!defined('SECURE_ACCESS')) {
    http_response_code(403);
    die('Direct access forbidden');
}

// Set timezone
date_default_timezone_set(env('APP_TIMEZONE', 'UTC'));

// Set memory limit for large operations
ini_set('memory_limit', '256M');

// Configure session settings
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', env('SESSION_SECURE_COOKIE', 'true') === 'true' ? '1' : '0');
ini_set('session.cookie_samesite', env('SESSION_SAME_SITE', 'Strict'));
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string) (env('SESSION_LIFETIME', 30) * 60));

// Set session save path to secure location
$sessionPath = env('SESSION_PATH', STORAGE_PATH . '/sessions');
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0755, true);
}
ini_set('session.save_path', $sessionPath);

// Initialize error handler
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Initialize exception handler
set_exception_handler(function ($exception) {
    error_log(sprintf(
        'Uncaught Exception: %s in %s:%d',
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    http_response_code(500);

    if (env('APP_DEBUG', false) === 'true' || env('APP_DEBUG', false) === true) {
        echo json_encode([
            'error' => 'Uncaught Exception',
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            'error' => 'Internal Server Error',
            'message' => 'An unexpected error occurred',
            'code' => 'INTERNAL_ERROR'
        ]);
    }
});

// Initialize database connection
require_once BASE_PATH . '/config/database.php';

// Initialize logging
require_once BASE_PATH . '/config/logging.php';

return true;
