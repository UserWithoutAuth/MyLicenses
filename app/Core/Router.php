<?php

namespace App\Core;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Router Class
 *
 * Handles HTTP routing and dispatching
 *
 * @package LicenseServer\Core
 */
class Router
{
    private array $routes = [];
    private array $middleware = [];
    private string $prefix = '';

    /**
     * Add a GET route
     *
     * @param string $uri
     * @param callable|array $action
     * @return Route
     */
    public function get(string $uri, $action): Route
    {
        return $this->addRoute('GET', $uri, $action);
    }

    /**
     * Add a POST route
     *
     * @param string $uri
     * @param callable|array $action
     * @return Route
     */
    public function post(string $uri, $action): Route
    {
        return $this->addRoute('POST', $uri, $action);
    }

    /**
     * Add a PUT route
     *
     * @param string $uri
     * @param callable|array $action
     * @return Route
     */
    public function put(string $uri, $action): Route
    {
        return $this->addRoute('PUT', $uri, $action);
    }

    /**
     * Add a DELETE route
     *
     * @param string $uri
     * @param callable|array $action
     * @return Route
     */
    public function delete(string $uri, $action): Route
    {
        return $this->addRoute('DELETE', $uri, $action);
    }

    /**
     * Add a PATCH route
     *
     * @param string $uri
     * @param callable|array $action
     * @return Route
     */
    public function patch(string $uri, $action): Route
    {
        return $this->addRoute('PATCH', $uri, $action);
    }

    /**
     * Add route for any method
     *
     * @param string $uri
     * @param callable|array $action
     * @return Route
     */
    public function any(string $uri, $action): Route
    {
        return $this->addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], $uri, $action);
    }

    /**
     * Group routes with common attributes
     *
     * @param array $attributes
     * @param callable $callback
     * @return void
     */
    public function group(array $attributes, callable $callback): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->middleware;

        // Apply group attributes
        if (isset($attributes['prefix'])) {
            $this->prefix = $previousPrefix . '/' . trim($attributes['prefix'], '/');
        }

        if (isset($attributes['middleware'])) {
            $this->middleware = array_merge(
                $this->middleware,
                (array) $attributes['middleware']
            );
        }

        // Execute callback
        call_user_func($callback, $this);

        // Restore previous state
        $this->prefix = $previousPrefix;
        $this->middleware = $previousMiddleware;
    }

    /**
     * Add a route
     *
     * @param string|array $methods
     * @param string $uri
     * @param callable|array $action
     * @return Route
     */
    private function addRoute($methods, string $uri, $action): Route
    {
        $methods = (array) $methods;
        $uri = $this->prefix . '/' . trim($uri, '/');
        $uri = '/' . trim($uri, '/');

        $route = new Route($methods, $uri, $action);
        $route->middleware($this->middleware);

        foreach ($methods as $method) {
            $this->routes[$method][$uri] = $route;
        }

        return $route;
    }

    /**
     * Dispatch the request
     *
     * @param Request $request
     * @return Response
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $uri = $request->getPathInfo();

        // Handle CORS preflight
        if ($method === 'OPTIONS') {
            return $this->handleCors();
        }

        // Find matching route
        $route = $this->findRoute($method, $uri);

        if ($route === null) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => 'The requested resource was not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Execute middleware
        foreach ($route->getMiddleware() as $middlewareClass) {
            $middleware = new $middlewareClass();
            $middlewareResponse = $middleware->handle($request);

            if ($middlewareResponse !== null) {
                return $middlewareResponse;
            }
        }

        // Execute route action
        try {
            $response = $this->executeAction($route->getAction(), $request, $route->getParameters());

            if ($response instanceof Response) {
                return $response;
            }

            if (is_array($response) || is_object($response)) {
                return new JsonResponse($response);
            }

            return new Response($response);

        } catch (\Throwable $e) {
            logger()->error('Route execution error', [
                'uri' => $uri,
                'method' => $method,
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Internal Server Error',
                'message' => env('APP_DEBUG') ? $e->getMessage() : 'An error occurred',
                'code' => 'INTERNAL_ERROR'
            ], 500);
        }
    }

    /**
     * Find matching route
     *
     * @param string $method
     * @param string $uri
     * @return Route|null
     */
    private function findRoute(string $method, string $uri): ?Route
    {
        // Direct match
        if (isset($this->routes[$method][$uri])) {
            return $this->routes[$method][$uri];
        }

        // Pattern matching
        foreach ($this->routes[$method] ?? [] as $routeUri => $route) {
            $pattern = $this->convertUriToRegex($routeUri);

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches); // Remove full match
                $route->setParameters($matches);
                return $route;
            }
        }

        return null;
    }

    /**
     * Convert URI to regex pattern
     *
     * @param string $uri
     * @return string
     */
    private function convertUriToRegex(string $uri): string
    {
        // Convert {param} to named capture group
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $uri);
        // Escape forward slashes
        $pattern = str_replace('/', '\/', $pattern);

        return '/^' . $pattern . '$/';
    }

    /**
     * Execute route action
     *
     * @param callable|array $action
     * @param Request $request
     * @param array $parameters
     * @return mixed
     */
    private function executeAction($action, Request $request, array $parameters = [])
    {
        if (is_callable($action)) {
            return call_user_func_array($action, [$request, ...$parameters]);
        }

        if (is_array($action) && count($action) === 2) {
            [$controller, $method] = $action;

            if (is_string($controller)) {
                $controller = new $controller();
            }

            return call_user_func_array([$controller, $method], [$request, ...$parameters]);
        }

        throw new \RuntimeException('Invalid route action');
    }

    /**
     * Handle CORS preflight request
     *
     * @return Response
     */
    private function handleCors(): Response
    {
        $response = new Response('', 200);

        $allowedOrigins = env('CORS_ALLOWED_ORIGINS', '*');
        $allowedMethods = env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,DELETE,OPTIONS');
        $allowedHeaders = env('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization,X-Requested-With');

        $response->headers->set('Access-Control-Allow-Origin', $allowedOrigins);
        $response->headers->set('Access-Control-Allow-Methods', $allowedMethods);
        $response->headers->set('Access-Control-Allow-Headers', $allowedHeaders);
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    /**
     * Get all routes
     *
     * @return array
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
