<?php

declare(strict_types=1);

namespace Loom\Router;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use function array_map;
use function array_reduce;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function strtoupper;

class Route implements RouteInterface, MiddlewareInterface
{
    /**
     * Route path
     *
     * @var string
     */
    private $path;

    /**
     * Middleware associated with route.
     *
     * @var MiddlewareInterface
     */
    private $middleware;

    /**
     * HTTP methods supported by this route
     *
     * @var null|string[]
     */
    private $methods;

    /**
     * Route name
     *
     * @var string
     */
    private $name;

    /**
     * Route options
     *
     * @var array.
     */
    private $options = [];

    /**
     * @param string $path The route path
     * @param MiddlewareInterface $middleware
     * @param string[]|null $methods The route HTTP methods
     * @param string|null $name The route name
     */
    public function __construct(
        string $path,
        MiddlewareInterface $middleware,
        array $methods = null,
        string $name = null
    ) {
        $this->path = $path;
        $this->middleware = $middleware;
        $this->methods = is_array($methods) ? $this->validateMethods($methods) : $methods;

        if (! $name) {
            $name = $this->methods === null ? $path : $path . '^' . implode(':', $this->methods);
        }
        $this->name = $name;
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
        return $this->middleware->process($request, $handler);
    }

    /**
     * Set route path
     *
     * @param string $path
     */
    public function setPath(string $path)
    {
        $this->path = $path;
    }

    /**
     * Get route path
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Set route name.
     *
     * @param string $name
     */
    public function setName(string $name)
    {
        $this->name = $name;
    }

    /**
     * Get route name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get middleware associated with the route
     *
     * @return MiddlewareInterface
     */
    public function getMiddleware(): MiddlewareInterface
    {
        return $this->middleware;
    }

    /**
     * Get route methods
     *
     * @return null|string[]
     */
    public function getMethods(): ?array
    {
        return $this->methods;
    }

    /**
     * Whether a given method is allowed
     *
     * @param string $method
     * @return bool
     */
    public function isAllowedMethod(string $method): bool
    {
        $method = strtoupper($method);
        if ($this->methods === null
            || in_array($method, $this->methods, true)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Set route options
     *
     * @param array $options
     */
    public function setOptions(array $options)
    {
        $this->options = $options;
    }

    /**
     * Get route options
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     *Validate and normalize provided HTTP methods
     * @param string[] $methods
     * @return string[]
     */
    private function validateMethods(array $methods): array
    {
        if (empty($methods)) {
            throw new Exception\InvalidArgumentException(
                'HTTP methods argument was empty; must contain at least one method'
            );
        }

        if (false === array_reduce($methods, function ($valid, $method) {
            if (false === $valid) {
                return false;
            }

            if (! is_string($method)) {
                return false;
            }

            if (! preg_match('/^[!#$%&\'*+.^_`|~0-9a-z-]+$/i', $method)) {
                return false;
            }

                return $valid;
        }, true)) {
            throw new Exception\InvalidArgumentException('One or more HTTP methods are invalid');
        }

        return array_map('strtoupper', $methods);
    }
}
