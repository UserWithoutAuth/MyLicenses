<?php

namespace App\Core;

/**
 * Route Class
 *
 * Represents an individual route
 *
 * @package LicenseServer\Core
 */
class Route
{
    private array $methods;
    private string $uri;
    private $action;
    private array $middleware = [];
    private array $parameters = [];
    private ?string $name = null;

    /**
     * Create a new route instance
     *
     * @param array $methods
     * @param string $uri
     * @param callable|array $action
     */
    public function __construct(array $methods, string $uri, $action)
    {
        $this->methods = $methods;
        $this->uri = $uri;
        $this->action = $action;
    }

    /**
     * Add middleware to route
     *
     * @param string|array $middleware
     * @return self
     */
    public function middleware($middleware): self
    {
        $this->middleware = array_merge($this->middleware, (array) $middleware);
        return $this;
    }

    /**
     * Set route name
     *
     * @param string $name
     * @return self
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set route parameters
     *
     * @param array $parameters
     * @return void
     */
    public function setParameters(array $parameters): void
    {
        $this->parameters = $parameters;
    }

    /**
     * Get route methods
     *
     * @return array
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * Get route URI
     *
     * @return string
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Get route action
     *
     * @return callable|array
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * Get route middleware
     *
     * @return array
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Get route parameters
     *
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get route name
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }
}
