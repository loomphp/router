<?php

declare(strict_types=1);

namespace Loom\Router;

use Psr\Http\Message\ServerRequestInterface as Request;

interface RouterInterface
{
    /**
     * Add a route.
     *
     * @param RouteInterface $route
     */
    public function addRoute(RouteInterface $route) : void;

    /**
     * Match a request against the known routes.
     *
     * @param Request $request
     * @return RouteResult
     */
    public function match(Request $request) : RouteResult;

    /**
     * Generate a URI from the named route.
     *
     * @param string $name
     * @param array $substitutions
     * @param array $options
     * @return string
     */
    public function generateUri(string $name, array $substitutions = [], array $options = []) : string;
}
