<?php
/**
 * API Routes
 *
 * All API routes for license management
 *
 * @package LicenseServer
 */

use App\Core\Application;

$app = Application::getInstance();
$router = $app->getRouter();

// API v1 routes
$router->group(['prefix' => 'api/v1'], function ($router) {

    // Health check endpoint
    $router->get('/health', function () {
        return [
            'status' => 'ok',
            'timestamp' => time(),
            'version' => '1.0.0',
            'service' => 'License Server'
        ];
    })->name('api.health');

    // License activation (online)
    $router->post('/licenses/activate', [\App\Controllers\LicenseController::class, 'activate'])
        ->name('api.licenses.activate');

    // License validation
    $router->post('/licenses/validate', [\App\Controllers\LicenseController::class, 'validate'])
        ->name('api.licenses.validate');

    // License deactivation
    $router->post('/licenses/deactivate', [\App\Controllers\LicenseController::class, 'deactivate'])
        ->name('api.licenses.deactivate');

    // Offline activation request
    $router->post('/licenses/offline/request', [\App\Controllers\LicenseController::class, 'offlineRequest'])
        ->name('api.licenses.offline.request');

    // Offline activation response
    $router->post('/licenses/offline/activate', [\App\Controllers\LicenseController::class, 'offlineActivate'])
        ->name('api.licenses.offline.activate');

    // Ping/heartbeat endpoint
    $router->post('/licenses/ping', [\App\Controllers\LicenseController::class, 'ping'])
        ->name('api.licenses.ping');

    // License transfer
    $router->post('/licenses/transfer', [\App\Controllers\LicenseController::class, 'transfer'])
        ->name('api.licenses.transfer');

    // Get license info
    $router->get('/licenses/{licenseKey}', [\App\Controllers\LicenseController::class, 'info'])
        ->name('api.licenses.info');

});

return $router;
