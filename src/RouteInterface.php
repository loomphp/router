<?php

declare(strict_types=1);

namespace Loom\Router;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

interface RouteInterface
{
    /**
     * Process an incoming server request.
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface;

    /**
     * Set route path
     *
     * @param string $path
     */
    public function setPath(string $path);

    /**
     * Get route path
     *
     * @return string
     */
    public function getPath(): string;

    /**
     * Set route name
     *
     * @param string $name
     */
    public function setName(string $name);

    /**
     * Get route name
     *
     * @return null|string
     */
    public function getName(): string;

    /**
     * Get route methods
     *
     * @return string[]
     */
    public function getMethods(): ?array;

    /**
     * Set route options
     *
     * @param array $options
     */
    public function setOptions(array $options);

    /**
     * Get route options
     *
     * @return array
     */
    public function getOptions(): array;
}
