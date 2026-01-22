<?php

namespace App\Middleware;

use App\Services\AuthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Authentication Middleware
 *
 * Ensures user is authenticated before accessing protected routes
 *
 * @package LicenseServer\Middleware
 */
class AuthMiddleware
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    /**
     * Handle the request
     *
     * @param Request $request
     * @return Response|null
     */
    public function handle(Request $request): ?Response
    {
        $user = $this->auth->getCurrentUser();

        if (!$user) {
            // Not authenticated
            if ($this->isApiRequest($request)) {
                return new Response(
                    json_encode([
                        'error' => 'Unauthorized',
                        'message' => 'Authentication required'
                    ]),
                    401,
                    ['Content-Type' => 'application/json']
                );
            }

            // Redirect to login
            return new RedirectResponse('/admin/login');
        }

        // Store user in request for controllers to access
        $request->attributes->set('user', $user);

        return null; // Continue to route
    }

    /**
     * Check if request is API request
     *
     * @param Request $request
     * @return bool
     */
    private function isApiRequest(Request $request): bool
    {
        return strpos($request->getPathInfo(), '/api/') === 0 ||
               $request->headers->get('Accept') === 'application/json';
    }
}
