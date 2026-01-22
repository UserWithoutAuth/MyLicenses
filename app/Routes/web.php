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

// Admin routes (will be expanded later with authentication)
$router->group(['prefix' => 'admin'], function ($router) {

    $router->get('/login', [\App\Controllers\Admin\AuthController::class, 'showLogin'])
        ->name('admin.login');

    $router->post('/login', [\App\Controllers\Admin\AuthController::class, 'login'])
        ->name('admin.login.post');

    $router->post('/logout', [\App\Controllers\Admin\AuthController::class, 'logout'])
        ->name('admin.logout');

    // Admin dashboard (requires authentication middleware)
    $router->get('/dashboard', [\App\Controllers\Admin\DashboardController::class, 'index'])
        ->name('admin.dashboard');

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
