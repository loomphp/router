<?php

declare(strict_types=1);

namespace Loom\Router;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RouteResult implements MiddlewareInterface
{
    /**
     * HTTP methods allowed with this route.
     *
     * @var null|string[]
     */
    private $allowedMethods = [];

    /**
     * Route matched parameters
     *
     * @var array
     */
    private $matchedParams = [];

    /**
     * Route matched name
     *
     * @var string
     */
    private $matchedRouteName;

    /**
     * Matching route
     *
     * @var Route $route
     */
    private $route;

    /**
     * Whether routing was successful
     *
     * @var bool
     */
    private $success;

    /**
     * @param RouteInterface $route
     * @param array $params
     * @return static
     */
    public static function fromRoute(RouteInterface $route, array $params = []): self
    {
        $result = new self();
        $result->success = true;
        $result->route = $route;
        $result->matchedParams = $params;
        return $result;
    }

    /**
     * @param array|null $methods
     * @return static
     */
    public static function fromRouteFailure(?array $methods): self
    {
        $result = new self();
        $result->success = false;
        $result->allowedMethods = $methods;

        return $result;
    }

    /**
     * Process an incoming server request.
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isFailure()) {
            return $handler->handle($request);
        }

        return $this->getMatchedRoute()->process($request, $handler);
    }

    /**
     * Whether routing was successful
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get matching route
     *
     * @return bool|Route
     */
    public function getMatchedRoute()
    {
        return $this->isFailure() ? false : $this->route;
    }

    /**
     * Get matched route name
     *
     * @return bool|string
     */
    public function getMatchedRouteName()
    {
        if ($this->isFailure()) {
            return false;
        }

        if (! $this->matchedRouteName && $this->route) {
            $this->matchedRouteName = $this->route->getName();
        }

        return $this->matchedRouteName;
    }

    /**
     * Get matched route parameters
     *
     * @return array
     */
    public function getMatchedParams(): array
    {
        return $this->matchedParams;
    }

    /**
     * Whether routing failed
     *
     * @return bool
     */
    public function isFailure(): bool
    {
        return (! $this->success);
    }

    /**
     * Whether routing failure is due to HTTP methods
     *
     * @return bool
     */
    public function isMethodFailure(): bool
    {
        if ($this->isSuccess() || $this->allowedMethods === null) {
            return false;
        }

        return true;
    }

    /**
     * Get HTTP methods allowed with this route.
     *
     * @return null|string[]
     */
    public function getAllowedMethods(): ?array
    {
        if ($this->isSuccess()) {
            return $this->route ? $this->route->getMethods() : [];
        }

        return $this->allowedMethods;
    }

    private function __construct()
    {
    }
}
