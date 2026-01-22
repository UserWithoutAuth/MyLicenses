<?php
/**
 * Web Routes
 *
 * Web routes for admin panel and customer portal
 *
 * @package LicenseServer
 */

use App\Core\Application;

$app = Application::getInstance();
$router = $app->getRouter();

// Home/Welcome page
$router->get('/', function () {
    return [
        'message' => 'License Server API',
        'version' => '1.0.0',
        'documentation' => env('APP_URL') . '/docs',
        'status' => 'operational'
    ];
})->name('home');

// Admin routes
$router->group(['prefix' => 'admin'], function ($router) {

    // Public routes (no auth required)
    $router->get('/login', [\App\Controllers\Admin\AuthController::class, 'showLogin'])
        ->name('admin.login');

    $router->post('/login', [\App\Controllers\Admin\AuthController::class, 'login'])
        ->name('admin.login.post');

    $router->post('/verify-2fa', [\App\Controllers\Admin\AuthController::class, 'verify2FA'])
        ->name('admin.verify2fa');

    // Protected routes (require authentication)
    $router->post('/logout', [\App\Controllers\Admin\AuthController::class, 'logout'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.logout');

    $router->get('/dashboard', [\App\Controllers\Admin\DashboardController::class, 'index'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.dashboard');

    $router->get('/api/statistics', [\App\Controllers\Admin\DashboardController::class, 'statistics'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.api.statistics');

    // License management
    $router->get('/licenses', [\App\Controllers\Admin\LicenseAdminController::class, 'index'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.licenses');

    $router->get('/licenses/create', [\App\Controllers\Admin\LicenseAdminController::class, 'create'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.licenses.create');

    $router->post('/licenses', [\App\Controllers\Admin\LicenseAdminController::class, 'store'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.licenses.store');

    $router->get('/licenses/{id}', [\App\Controllers\Admin\LicenseAdminController::class, 'show'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.licenses.show');

    $router->post('/licenses/{id}/revoke', [\App\Controllers\Admin\LicenseAdminController::class, 'revoke'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.licenses.revoke');

    // Backup management
    $router->get('/backups', [\App\Controllers\Admin\BackupController::class, 'index'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.backups');

    $router->post('/backups/create', [\App\Controllers\Admin\BackupController::class, 'create'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.backups.create');

    $router->get('/backups/download/{filename}', [\App\Controllers\Admin\BackupController::class, 'download'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.backups.download');

    $router->delete('/backups/delete/{filename}', [\App\Controllers\Admin\BackupController::class, 'delete'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.backups.delete');

    $router->get('/backups/config', [\App\Controllers\Admin\BackupController::class, 'getConfig'])
        ->middleware([\App\Middleware\AuthMiddleware::class])
        ->name('admin.backups.config');

});

// Customer portal routes
$router->group(['prefix' => 'portal'], function ($router) {

    $router->get('/login', [\App\Controllers\Portal\AuthController::class, 'showLogin'])
        ->name('portal.login');

    $router->post('/login', [\App\Controllers\Portal\AuthController::class, 'login'])
        ->name('portal.login.post');

    $router->get('/licenses', [\App\Controllers\Portal\LicenseController::class, 'index'])
        ->name('portal.licenses');

});

return $router;
