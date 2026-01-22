<?php

namespace App\Core;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Main Application Class
 *
 * Handles HTTP requests and routing
 *
 * @package LicenseServer\Core
 */
class Application
{
    private static ?Application $instance = null;
    private Router $router;
    private Request $request;

    /**
     * Private constructor for singleton pattern
     */
    private function __construct()
    {
        $this->request = Request::createFromGlobals();
        $this->router = new Router();
        $this->loadRoutes();
    }

    /**
     * Get application instance (Singleton)
     *
     * @return Application
     */
    public static function getInstance(): Application
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Run the application
     *
     * @return void
     */
    public function run(): void
    {
        try {
            // Apply security middleware
            $this->applySecurityHeaders();

            // Check rate limiting
            $this->checkRateLimit();

            // Dispatch the request
            $response = $this->router->dispatch($this->request);

            // Send response
            $response->send();

        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Load route files
     *
     * @return void
     */
    private function loadRoutes(): void
    {
        // API routes
        if (file_exists(APP_PATH . '/Routes/api.php')) {
            require_once APP_PATH . '/Routes/api.php';
        }

        // Web routes
        if (file_exists(APP_PATH . '/Routes/web.php')) {
            require_once APP_PATH . '/Routes/web.php';
        }
    }

    /**
     * Apply security headers
     *
     * @return void
     */
    private function applySecurityHeaders(): void
    {
        if (!env('SECURITY_HEADERS_ENABLED', true)) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        // HSTS (only if HTTPS)
        if ($this->request->isSecure() || env('FORCE_HTTPS', false)) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        // Content Security Policy
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none';");
    }

    /**
     * Check rate limiting
     *
     * @return void
     */
    private function checkRateLimit(): void
    {
        if (!env('RATE_LIMIT_ENABLED', true)) {
            return;
        }

        $rateLimiter = new \App\Services\RateLimitService();
        $clientIp = get_client_ip();

        if (!$rateLimiter->attempt($clientIp)) {
            audit_log('rate_limit_exceeded', ['ip' => $clientIp]);

            response_json([
                'error' => 'Too many requests',
                'message' => 'Please slow down and try again later',
                'retry_after' => $rateLimiter->availableIn($clientIp)
            ], 429);
        }
    }

    /**
     * Handle application exceptions
     *
     * @param \Throwable $e
     * @return void
     */
    private function handleException(\Throwable $e): void
    {
        // Log the exception
        logger()->error('Application Exception', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        // Determine response based on debug mode
        if (env('APP_DEBUG', false)) {
            response_json([
                'error' => 'Application Error',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString())
            ], 500);
        } else {
            response_json([
                'error' => 'Internal Server Error',
                'message' => 'An unexpected error occurred',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    /**
     * Get router instance
     *
     * @return Router
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Get request instance
     *
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }
}
