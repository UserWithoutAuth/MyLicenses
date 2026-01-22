<?php
/**
 * License Server - Main Entry Point
 *
 * All HTTP requests are routed through this file.
 * Sensitive files are kept outside public_html for security.
 *
 * @package LicenseServer
 * @version 1.0.0
 * @author License Server Team
 */

// Security check - prevent direct access to other PHP files
define('SECURE_ACCESS', true);

// Define directory paths (outside public_html)
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('CONFIG_PATH', BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PUBLIC_PATH', __DIR__);
define('DATABASE_PATH', BASE_PATH . '/database');

// Set error reporting based on environment
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php_errors.log');

// Security: Disable dangerous PHP functions
if (function_exists('ini_set')) {
    ini_set('allow_url_fopen', '0');
    ini_set('allow_url_include', '0');
    ini_set('expose_php', '0');
}

// Check if vendor autoload exists
if (!file_exists(BASE_PATH . '/vendor/autoload.php')) {
    http_response_code(500);
    die(json_encode([
        'error' => 'Dependencies not installed',
        'message' => 'Please run: composer install',
        'code' => 'DEPENDENCIES_MISSING'
    ]));
}

// Load Composer autoloader
require_once BASE_PATH . '/vendor/autoload.php';

// Check if .env file exists
if (!file_exists(BASE_PATH . '/.env')) {
    http_response_code(500);
    die(json_encode([
        'error' => 'Configuration missing',
        'message' => 'Please create .env file from .env.example',
        'code' => 'ENV_MISSING'
    ]));
}

try {
    // Load environment variables
    $dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
    $dotenv->load();

    // Validate required environment variables
    $dotenv->required([
        'APP_KEY',
        'DB_HOST',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD'
    ])->notEmpty();

} catch (\Dotenv\Exception\ValidationException $e) {
    http_response_code(500);
    die(json_encode([
        'error' => 'Configuration error',
        'message' => 'Missing required environment variables',
        'code' => 'ENV_INVALID'
    ]));
}

// Check maintenance mode
if (env('MAINTENANCE_MODE', false) === 'true' || env('MAINTENANCE_MODE', false) === true) {
    // Allow access with maintenance secret or from allowed IPs
    $maintenanceSecret = $_GET['secret'] ?? '';
    $allowedIps = explode(',', env('MAINTENANCE_ALLOWED_IPS', ''));
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($maintenanceSecret !== env('MAINTENANCE_SECRET') && !in_array($clientIp, $allowedIps)) {
        http_response_code(503);
        header('Retry-After: 3600');
        die(json_encode([
            'error' => 'Service Unavailable',
            'message' => 'System is currently under maintenance',
            'code' => 'MAINTENANCE_MODE'
        ]));
    }
}

// Bootstrap the application
require_once BASE_PATH . '/bootstrap.php';

// Handle the request
try {
    $app = App\Core\Application::getInstance();
    $app->run();

} catch (\Throwable $e) {
    // Log the error
    error_log('Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // Show user-friendly error
    http_response_code(500);

    if (env('APP_DEBUG', false) === 'true' || env('APP_DEBUG', false) === true) {
        die(json_encode([
            'error' => 'Internal Server Error',
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'code' => 'INTERNAL_ERROR'
        ], JSON_PRETTY_PRINT));
    } else {
        die(json_encode([
            'error' => 'Internal Server Error',
            'message' => 'An unexpected error occurred. Please contact support.',
            'code' => 'INTERNAL_ERROR'
        ]));
    }
}
