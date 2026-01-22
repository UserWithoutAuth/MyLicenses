<?php

namespace App\Middleware;

use App\Services\AuthService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission Middleware
 *
 * Checks if user has required permission
 *
 * @package LicenseServer\Middleware
 */
class PermissionMiddleware
{
    private AuthService $auth;
    private string $requiredPermission;

    public function __construct(string $permission = '')
    {
        $this->auth = new AuthService();
        $this->requiredPermission = $permission;
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
            return new Response(
                json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Authentication required'
                ]),
                401,
                ['Content-Type' => 'application/json']
            );
        }

        if ($this->requiredPermission && !$user->can($this->requiredPermission)) {
            audit_log('access.denied', [
                'user_id' => $user->id,
                'permission' => $this->requiredPermission,
                'path' => $request->getPathInfo()
            ]);

            return new Response(
                json_encode([
                    'error' => 'Forbidden',
                    'message' => 'You do not have permission to access this resource'
                ]),
                403,
                ['Content-Type' => 'application/json']
            );
        }

        return null; // Permission granted, continue
    }

    /**
     * Set required permission
     *
     * @param string $permission
     * @return self
     */
    public function requires(string $permission): self
    {
        $this->requiredPermission = $permission;
        return $this;
    }
}
