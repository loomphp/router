<?php

declare(strict_types=1);

namespace Loom\Router;

use FastRoute\DataGenerator\GroupCountBased as RouteGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as RouteParser;
use Fig\Http\Message\RequestMethodInterface as RequestMethod;
use Loom\Util\ArrayUtil;
use Psr\Http\Message\ServerRequestInterface as Request;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_reduce;
use function array_reverse;
use function array_unique;
use function dirname;
use function file_exists;
use function file_put_contents;
use function implode;
use function is_array;
use function is_dir;
use function is_string;
use function is_writable;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function var_export;
use const E_WARNING;

class Router implements RouterInterface
{
    public const CACHE_TEMPLATE = <<< 'EOT'
<?php
return %s;
EOT;

    public const CONFIG_CACHE_ENABLED = 'cache_enabled';

    public const CONFIG_CACHE_FILE = 'cache_file';

    public const HTTP_METHODS_STANDARD = [
        RequestMethod::METHOD_HEAD,
        RequestMethod::METHOD_GET,
        RequestMethod::METHOD_POST,
        RequestMethod::METHOD_PUT,
        RequestMethod::METHOD_PATCH,
        RequestMethod::METHOD_DELETE,
        RequestMethod::METHOD_OPTIONS,
        RequestMethod::METHOD_TRACE,
    ];

    private $cacheEnabled = false;

    private $cacheFile = 'data/cache/fastroute.php.cache';

    private $dispatcherCallback;

    private $dispatchData = [];

    private $hasCache = false;

    private $router;

    private $routes = [];

    private $routesToInject = [];

    public function __construct(
        RouteCollector $router = null,
        callable $dispatcherFactory = null,
        array $config = null
    ) {
        if (null === $router) {
            $router = $this->createRouter();
        }

        $this->router = $router;
        $this->dispatcherCallback = $dispatcherFactory;

        $this->loadConfig($config);
    }

    private function loadConfig(array $config = null): void
    {
        if (null === $config) {
            return;
        }

        if (isset($config[self::CONFIG_CACHE_ENABLED])) {
            $this->cacheEnabled = (bool)$config[self::CONFIG_CACHE_ENABLED];
        }

        if (isset($config[self::CONFIG_CACHE_FILE])) {
            $this->cacheFile = (string)$config[self::CONFIG_CACHE_FILE];
        }

        if ($this->cacheEnabled) {
            $this->loadDispatchData();
        }
    }

    public function addRoute(RouteInterface $route): void
    {
        $this->routesToInject[] = $route;
    }

    public function match(Request $request): RouteResult
    {
        $this->injectRoutes();

        $dispatchData = $this->getDispatchData();

        $path = rawurldecode($request->getUri()->getPath());
        $method = $request->getMethod();
        $dispatcher = $this->getDispatcher($dispatchData);
        $result = $dispatcher->dispatch($method, $path);

        return $result[0] !== Dispatcher::FOUND
            ? $this->marshalFailedRoute($result)
            : $this->marshalMatchedRoute($result, $method);
    }

    public function generateUri(string $name, array $substitutions = [], array $options = []): string
    {
        $this->injectRoutes();

        if (! array_key_exists($name, $this->routes)) {
            throw new Exception\RuntimeException(sprintf(
                'Cannot generate URI for route "%s"; route not found',
                $name
            ));
        }

        $route = $this->routes[$name];
        $options = ArrayUtil::mergeArray($route->getOptions(), $options);

        if (! empty($options['defaults'])) {
            $substitutions = array_merge($options['defaults'], $substitutions);
        }

        $routeParser = new RouteParser();
        $routes = array_reverse($routeParser->parse($route->getPath()));
        $missingParameters = [];

        foreach ($routes as $parts) {
            $missingParameters = $this->missingParameters($parts, $substitutions);

            if (! empty($missingParameters)) {
                continue;
            }

            $path = '';
            foreach ($parts as $part) {
                if (is_string($part)) {
                    $path .= $part;
                    continue;
                }

                if (! preg_match('~^' . $part[1] . '$~', (string)$substitutions[$part[0]])) {
                    throw new Exception\RuntimeException(sprintf(
                        'Parameter value for [%s] did not match the regex `%s`',
                        $part[0],
                        $part[1]
                    ));
                }

                $path .= $substitutions[$part[0]];
            }

            return $path;
        }

        throw new Exception\RuntimeException(sprintf(
            'Route `%s` expects at least parameter values for [%s], but received [%s]',
            $name,
            implode(',', $missingParameters),
            implode(',', array_keys($substitutions))
        ));
    }

    private function missingParameters(array $parts, array $substitutions): array
    {
        $missingParameters = [];

        foreach ($parts as $part) {
            if (is_string($part)) {
                continue;
            }

            $missingParameters[] = $part[0];
        }

        foreach ($missingParameters as $param) {
            if (! isset($substitutions[$param])) {
                return $missingParameters;
            }
        }

        return [];
    }

    private function createRouter(): RouteCollector
    {
        return new RouteCollector(new RouteParser, new RouteGenerator);
    }

    private function getDispatcher($data): Dispatcher
    {
        if (! $this->dispatcherCallback) {
            $this->dispatcherCallback = $this->createDispatcherCallback();
        }

        $factory = $this->dispatcherCallback;

        return $factory($data);
    }

    private function createDispatcherCallback(): callable
    {
        return function ($data) {
            return new GroupCountBased($data);
        };
    }

    private function marshalFailedRoute(array $result): RouteResult
    {
        if ($result[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            return RouteResult::fromRouteFailure($result[1]);
        }

        return RouteResult::fromRouteFailure(null);
    }

    private function marshalMatchedRoute(array $result, string $method): RouteResult
    {
        $path = $result[1];
        $route = array_reduce($this->routes, function ($matched, $route) use ($path, $method) {
            if ($matched) {
                return $matched;
            }

            if ($path !== $route->getPath()) {
                return $matched;
            }

            if (! $route->isAllowedMethod($method)) {
                return $matched;
            }

            return $route;
        }, false);

        if (false === $route) {
            return $this->marshalMethodNotAllowedResult($result);
        }

        $params = $result[2];

        $options = $route->getOptions();
        if (! empty($options['defaults'])) {
            $params = array_merge($options['defaults'], $params);
        }

        return RouteResult::fromRoute($route, $params);
    }

    private function injectRoutes(): void
    {
        foreach ($this->routesToInject as $index => $route) {
            $this->injectRoute($route);
            unset($this->routesToInject[$index]);
        }
    }

    private function injectRoute(Route $route): void
    {
        $this->routes[$route->getName()] = $route;

        if ($this->hasCache) {
            return;
        }

        $methods = $route->getMethods();

        if ($methods === null) {
            $methods = self::HTTP_METHODS_STANDARD;
        }

        $this->router->addRoute($methods, $route->getPath(), $route->getPath());
    }

    private function getDispatchData(): array
    {
        if ($this->hasCache) {
            return $this->dispatchData;
        }

        $dispatchData = (array)$this->router->getData();

        if ($this->cacheEnabled) {
            $this->cacheDispatchData($dispatchData);
        }

        return $dispatchData;
    }

    private function loadDispatchData(): void
    {
        set_error_handler(function () {
        }, E_WARNING);
        $dispatchData = include $this->cacheFile;
        restore_error_handler();

        if (false === $dispatchData) {
            return;
        }

        if (! is_array($dispatchData)) {
            throw new Exception\InvalidCacheException(sprintf(
                'Invalid cache file "%s"; cache file MUST return an array',
                $this->cacheFile
            ));
        }

        $this->hasCache = true;
        $this->dispatchData = $dispatchData;
    }

    private function cacheDispatchData(array $dispatchData)
    {
        $cacheDir = dirname($this->cacheFile);

        if (! is_dir($cacheDir)) {
            throw new Exception\InvalidCacheDirectoryException(sprintf(
                'The cache directory "%s" does not exist',
                $cacheDir
            ));
        }

        if (! is_writable($cacheDir)) {
            throw new Exception\InvalidCacheDirectoryException(sprintf(
                'The cache directory "%s" is not writable',
                $cacheDir
            ));
        }

        if (file_exists($this->cacheFile) && ! is_writable($this->cacheFile)) {
            throw new Exception\InvalidCacheException(sprintf(
                'The cache file %s is not writable',
                $this->cacheFile
            ));
        }

        return file_put_contents(
            $this->cacheFile,
            sprintf(self::CACHE_TEMPLATE, var_export($dispatchData, true))
        );
    }

    private function marshalMethodNotAllowedResult(array $result): RouteResult
    {
        $path = $result[1];
        $allowedMethods = array_reduce($this->routes, function ($allowedMethods, $route) use ($path) {
            if ($path !== $route->getPath()) {
                return $allowedMethods;
            }

            return array_merge($allowedMethods, $route->getMethods());
        }, []);

        $allowedMethods = array_unique($allowedMethods);

        return RouteResult::fromRouteFailure($allowedMethods);
    }
}
