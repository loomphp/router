<?php

declare(strict_types=1);

namespace LoomTest\Router;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Zend\Diactoros\ServerRequest;
use Loom\Router\Exception\InvalidCacheDirectoryException;
use Loom\Router\Exception\InvalidCacheException;
use Loom\Router\Exception\RuntimeException;
use Loom\Router\Router;
use Loom\Router\Route;
use Loom\Router\RouteResult;

use function file_get_contents;
use function is_file;
use function unlink;

class RouterTest extends TestCase
{
    private $fastRouter;
    private $dispatcher;
    private $dispatchCallback;

    protected function setUp()
    {
        $this->fastRouter = $this->prophesize(RouteCollector::class);
        $this->dispatcher = $this->prophesize(Dispatcher::class);
        $this->dispatchCallback = function () {
            return $this->dispatcher->reveal();
        };
    }

    private function getRouter() : Router
    {
        return new Router(
            $this->fastRouter->reveal(),
            $this->dispatchCallback
        );
    }

    private function getMiddleware() : MiddlewareInterface
    {
        return $this->prophesize(MiddlewareInterface::class)->reveal();
    }

    public function testLazyInstantiationOfFastRouteCollector()
    {
        $router = new Router();
        $this->assertAttributeInstanceOf(RouteCollector::class, 'router', $router);
    }

    public function testAddingRouteAggregatesRoute()
    {
        $route = new Route('/foo', $this->getMiddleware(), ['GET']);
        $router = $this->getRouter();
        $router->addRoute($route);
        $this->assertAttributeContains($route, 'routesToInject', $router);
    }

    public function testMatchingInjectsRouteIntoFastRoute()
    {
        $route = new Route('/foo', $this->getMiddleware(), ['GET']);
        $this->fastRouter->addRoute(['GET'], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();
        $this->dispatcher->dispatch('GET', '/foo')->willReturn([
            Dispatcher::NOT_FOUND,
        ]);

        $router = $this->getRouter();
        $router->addRoute($route);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->will(function () use ($uri) {
            return $uri->reveal();
        });
        $request->getMethod()->willReturn('GET');

        $router->match($request->reveal());
    }

    public function testGeneratingUriInjectsRouteIntoFastRoute()
    {
        $route = new Route('/foo', $this->getMiddleware(), ['GET'], 'foo');
        $this->fastRouter->addRoute(['GET'], '/foo', '/foo')->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);

        $this->assertEquals('/foo', $router->generateUri('foo'));
    }

    public function testIfRouteSpecifiesAnyHttpMethodFastRouteIsPassedHardCodedListOfMethods()
    {
        $route = new Route('/foo', $this->getMiddleware());
        $this->fastRouter
            ->addRoute(
                Router::HTTP_METHODS_STANDARD,
                '/foo',
                '/foo'
            )
            ->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);

        $router->generateUri($route->getName());
    }

    public function testMatchingRouteShouldReturnSuccessfulRouteResult()
    {
        $middleware = $this->getMiddleware();
        $route = new Route('/foo', $middleware, ['GET']);

        $uri     = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('GET');

        $this->dispatcher->dispatch('GET', '/foo')->willReturn([
            Dispatcher::FOUND,
            '/foo',
            ['bar' => 'baz']
        ]);

        $this->fastRouter->addRoute(['GET'], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('/foo^GET', $result->getMatchedRouteName());
        $this->assertSame($middleware, $result->getMatchedRoute()->getMiddleware());
        $this->assertSame(['bar' => 'baz'], $result->getMatchedParams());
        $this->assertSame($route, $result->getMatchedRoute());
    }

    public function matchWithUrlEncodedSpecialCharsDataProvider()
    {
        return [
            'encoded-space'   => ['/foo/{id:.+}', '/foo/b%20ar', 'b ar'],
            'encoded-slash'   => ['/foo/{id:.+}', '/foo/b%2Fr', 'b/r'],
            'encoded-unicode' => ['/foo/{id:.+}', '/foo/bar-%E6%B8%AC%E8%A9%A6', 'bar-測試'],
            'encoded-regex'   => ['/foo/{id:bär}', '/foo/b%C3%A4r', 'bär'],
            'unencoded-regex' => ['/foo/{id:bär}', '/foo/bär', 'bär'],
        ];
    }

    /**
     * @dataProvider matchWithUrlEncodedSpecialCharsDataProvider
     */
    public function testMatchWithUrlEncodedSpecialChars($routePath, $requestPath, $expectedId)
    {
        $request = $this->createServerRequestProphecy($requestPath, 'GET');

        $route = new Route($routePath, $this->getMiddleware(), ['GET'], 'foo');

        $router = new Router();
        $router->addRoute($route);

        $routeResult = $router->match($request->reveal());

        $this->assertTrue($routeResult->isSuccess());
        $this->assertSame('foo', $routeResult->getMatchedRouteName());
        $this->assertSame(
            ['id' => $expectedId ],
            $routeResult->getMatchedParams()
        );
    }

    public function idemPotentMethods()
    {
        return [
            'GET' => ['GET'],
            'HEAD' => ['HEAD'],
        ];
    }

    /**
     * @dataProvider idemPotentMethods
     */
    public function testRouteNotSpecifyingOptionsImpliesOptionsIsSupportedAndMatchesWhenGetOrHeadIsAllowed(
        string $method
    ) {
        $route = new Route('/foo', $this->getMiddleware(), ['POST', $method]);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('OPTIONS');

        $router = new Router();
        $router->addRoute($route);
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->getMatchedRoute());
        $this->assertSame(['POST', $method], $result->getAllowedMethods());
    }

    public function testRouteNotSpecifyingOptionsGetOrHeadMatchesOptions()
    {
        $route = new Route('/foo', $this->getMiddleware(), ['POST']);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('OPTIONS');

        $router = new Router();
        $router->addRoute($route);
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertSame(['POST'], $result->getAllowedMethods());
    }

    public function testRouteNotSpecifyingGetOrHeadDoesMatcheshHead()
    {
        $route = new Route('/foo', $this->getMiddleware(), ['POST']);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('HEAD');

        $router = new Router();
        $router->addRoute($route);
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertSame(['POST'], $result->getAllowedMethods());
    }

    public function testRouteSpecifyingGetDoesNotMatchHead()
    {
        $route = new Route('/foo', $this->getMiddleware(), ['GET']);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('HEAD');

        $router = new Router();
        $router->addRoute($route);
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertFalse($result->isSuccess());
    }

    public function testMatchFailureDueToHttpMethodReturnsRouteResultWithAllowedMethods()
    {
        $route = new Route('/foo', $this->getMiddleware(), ['POST']);

        $uri     = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('GET');

        $this->dispatcher->dispatch('GET', '/foo')->willReturn([
            Dispatcher::METHOD_NOT_ALLOWED,
            ['POST']
        ]);

        $this->fastRouter->addRoute(['POST'], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isMethodFailure());
        $this->assertSame(['POST'], $result->getAllowedMethods());
    }

    public function testMatchFailureNotDueToHttpMethodReturnsGenericRouteFailureResult()
    {
        $route = new Route('/foo', $this->getMiddleware(), ['GET']);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/bar');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('GET');

        $this->dispatcher->dispatch('GET', '/bar')->willReturn([
            Dispatcher::NOT_FOUND,
        ]);

        $this->fastRouter->addRoute(['GET'], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isMethodFailure());
        $this->assertSame(null, $result->getAllowedMethods());
    }

    public function generatedUriProvider()
    {
        // @codingStandardsIgnoreStart
        $routes = [
            new Route('/foo', $this->getMiddleware(), ['POST'], 'foo-create'),
            new Route('/foo', $this->getMiddleware(), ['GET'], 'foo-list'),
            new Route('/foo/{id:\d+}', $this->getMiddleware(), ['GET'], 'foo'),
            new Route('/bar/{baz}', $this->getMiddleware(), null, 'bar'),
            new Route('/index[/{page:\d+}]', $this->getMiddleware(), ['GET'], 'index'),
            new Route('/extra[/{page:\d+}[/optional-{extra:\w+}]]', $this->getMiddleware(), ['GET'], 'extra'),
            new Route('/page[/{page:\d+}/{locale:[a-z]{2}}[/optional-{extra:\w+}]]', $this->getMiddleware(), ['GET'], 'limit'),
            new Route('/api/{res:[a-z]+}[/{resId:\d+}[/{rel:[a-z]+}[/{relId:\d+}]]]', $this->getMiddleware(), ['GET'], 'api'),
            new Route('/optional-regex[/{optional:prefix-[a-z]+}]', $this->getMiddleware(), ['GET'], 'optional-regex'),
        ];

        return [
            // Test case                 routes   expected URI                   generateUri arguments
            'foo-create'             => [$routes, '/foo',                        ['foo-create']],
            'foo-list'               => [$routes, '/foo',                        ['foo-list']],
            'foo'                    => [$routes, '/foo/42',                     ['foo', ['id' => 42]]],
            'bar'                    => [$routes, '/bar/BAZ',                    ['bar', ['baz' => 'BAZ']]],
            'index'                  => [$routes, '/index',                      ['index']],
            'index-page'             => [$routes, '/index/42',                   ['index', ['page' => 42]]],
            'extra-42'               => [$routes, '/extra/42',                   ['extra', ['page' => 42]]],
            'extra-optional-segment' => [$routes, '/extra/42/optional-segment',  ['extra', ['page' => 42, 'extra' => 'segment']]],
            'limit'                  => [$routes, '/page/2/en/optional-segment', ['limit', ['locale' => 'en', 'page' => 2, 'extra' => 'segment']]],
            'api-optional-regex'     => [$routes, '/api/foo',                    ['api', ['res' => 'foo']]],
            'api-resource-id'        => [$routes, '/api/foo/1',                  ['api', ['res' => 'foo', 'resId' => 1]]],
            'api-relation'           => [$routes, '/api/foo/1/bar',              ['api', ['res' => 'foo', 'resId' => 1, 'rel' => 'bar']]],
            'api-relation-id'        => [$routes, '/api/foo/1/bar/2',            ['api', ['res' => 'foo', 'resId' => 1, 'rel' => 'bar', 'relId' => 2]]],
            'optional-regex'         => [$routes, '/optional-regex',             ['optional-regex']],
        ];
        // @codingStandardsIgnoreEnd
    }

    /**
     * @dataProvider generatedUriProvider
     */
    public function testCanGenerateUriFromRoutes(array $routes, $expected, array $generateArgs)
    {
        $router = new Router();
        foreach ($routes as $route) {
            $router->addRoute($route);
        }

        $uri = $router->generateUri(... $generateArgs);
        $this->assertEquals($expected, $uri);
    }

    public function testOptionsPassedToGenerateUriOverrideThoseFromRoute()
    {
        $route  = new Route(
            '/page[/{page:\d+}/{locale:[a-z]{2}}[/optional-{extra:\w+}]]',
            $this->getMiddleware(),
            ['GET'],
            'limit'
        );
        $route->setOptions(['defaults' => [
            'page'   => 1,
            'locale' => 'en',
            'extra'  => 'tag',
        ]]);

        $router = new Router();
        $router->addRoute($route);

        $uri = $router->generateUri('limit', [], ['defaults' => [
            'page'   => 5,
            'locale' => 'de',
            'extra'  => 'sort',
        ]]);
        $this->assertEquals('/page/5/de/optional-sort', $uri);
    }

    public function testReturnedRouteResultShouldContainRouteName()
    {
        $route = new Route('/foo', $this->getMiddleware(), ['GET'], 'foo-route');

        $uri     = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->willReturn($uri);
        $request->getMethod()->willReturn('GET');

        $this->dispatcher->dispatch('GET', '/foo')->willReturn([
            Dispatcher::FOUND,
            '/foo',
            ['bar' => 'baz']
        ]);

        $this->fastRouter->addRoute(['GET'], '/foo', '/foo')->shouldBeCalled();
        $this->fastRouter->getData()->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('foo-route', $result->getMatchedRouteName());
    }

    public function uriGeneratorDataProvider()
    {
        return [
            ['/foo/abc/def', []],
            ['/foo/123/def', ['param1' => '123']],
            ['/foo/abc/456', ['param2' => '456']],
            ['/foo/123/456', ['param1' => '123', 'param2' => '456']],
        ];
    }

    /**
     * @dataProvider uriGeneratorDataProvider
     */
    public function testUriGenerationSubstitutionsWithDefaultOptions($expectedUri, array $params)
    {
        $router = new Router();

        $route = new Route('/foo/{param1}/{param2}', $this->getMiddleware(), ['GET'], 'foo');
        $route->setOptions([
            'defaults' => [
                'param1' => 'abc',
                'param2' => 'def',
            ],
        ]);

        $router->addRoute($route);

        $this->assertEquals($expectedUri, $router->generateUri('foo', $params));
    }

    /**
     * @dataProvider uriGeneratorDataProvider
     */
    public function testUriGenerationSubstitutionsWithDefaultsAndOptionalParameters($expectedUri, array $params)
    {
        $router = new Router();

        $route = new Route('/foo/{param1}/{param2}', $this->getMiddleware(), ['GET'], 'foo');
        $route->setOptions([
            'defaults' => [
                'param1' => 'abc',
                'param2' => 'def',
            ],
        ]);

        $router->addRoute($route);

        $this->assertEquals($expectedUri, $router->generateUri('foo', $params));
    }

    public function uriGeneratorWithPartialDefaultsDataProvider()
    {
        return [
            ['/foo/abc', []],
            ['/foo/123', ['param1' => '123']],
            ['/foo/abc/456', ['param2' => '456']],
            ['/foo/123/456', ['param1' => '123', 'param2' => '456']],
        ];
    }

    /**
     * @dataProvider uriGeneratorWithPartialDefaultsDataProvider
     */
    public function testUriGenerationSubstitutionsWithPartialDefaultsAndOptionalParameters($expectedUri, array $params)
    {
        $router = new Router();

        $route = new Route('/foo/{param1}[/{param2}]', $this->getMiddleware(), ['GET'], 'foo');
        $route->setOptions([
            'defaults' => [
                'param1' => 'abc',
            ],
        ]);

        $router->addRoute($route);

        $this->assertEquals($expectedUri, $router->generateUri('foo', $params));
    }

    public function createCachingRouter(array $config, Route $route)
    {
        $router = new Router(null, null, $config);
        $router->addRoute($route);

        return $router;
    }

    public function createServerRequestProphecy($path, $method = 'GET')
    {
        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn($path);

        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getUri()->will(function () use ($uri) {
            return $uri->reveal();
        });

        $request->getMethod()->willReturn($method);

        return $request;
    }

    public function testFastRouteCache()
    {
        $cache_file = __DIR__ . '/fastroute.cache';

        $config = [
            Router::CONFIG_CACHE_ENABLED => true,
            Router::CONFIG_CACHE_FILE    => $cache_file,
        ];

        $request = $this->createServerRequestProphecy('/foo', 'GET');

        $middleware = $this->getMiddleware();
        $route = new Route('/foo', $middleware, ['GET'], 'foo');

        $router1 = $this->createCachingRouter($config, $route);
        $router1->match($request->reveal());

        $this->assertTrue(is_file($cache_file));

        $cache1 = file_get_contents($cache_file);

        $router2 = $this->createCachingRouter($config, $route);

        $result = $router2->match($request->reveal());

        $this->assertTrue(is_file($cache_file));

        $cache2 = file_get_contents($cache_file);

        $this->assertEquals($cache1, $cache2);

        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('foo', $result->getMatchedRouteName());
        $this->assertSame($middleware, $result->getMatchedRoute()->getMiddleware());

        unlink($cache_file);
    }

    public function testGenerateUriRaisesExceptionForMissingMandatoryParameters()
    {
        $router = new Router();
        $route = new Route('/foo/{id}', $this->getMiddleware(), ['GET'], 'foo');
        $router->addRoute($route);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expects at least parameter values for');

        $router->generateUri('foo');
    }

    public function testGenerateUriRaisesExceptionForNotFoundRoute()
    {
        $router = new Router();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('route not found');
        $router->generateUri('foo');
    }

    public function testRouteResultContainsDefaultAndMatchedParams()
    {
        $route = new Route('/foo/{id}', $this->getMiddleware());
        $route->setOptions(['defaults' => ['bar' => 'baz']]);

        $router = new Router();
        $router->addRoute($route);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => 'GET'],
            [],
            '/foo/my-id',
            'GET'
        );

        $result = $router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame(['bar' => 'baz', 'id' => 'my-id'], $result->getMatchedParams());
    }

    public function testMatchedRouteParamsOverrideDefaultParams()
    {
        $route = new Route('/foo/{bar}', $this->getMiddleware());
        $route->setOptions(['defaults' => ['bar' => 'baz']]);

        $router = new Router();
        $router->addRoute($route);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => 'GET'],
            [],
            '/foo/var',
            'GET'
        );

        $result = $router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame(['bar' => 'var'], $result->getMatchedParams());
    }

    public function testMatchedCorrectRoute()
    {
        $route1 = new Route('/foo', $this->getMiddleware());
        $route2 = new Route('/bar', $this->getMiddleware());

        $router = new Router();
        $router->addRoute($route1);
        $router->addRoute($route2);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => 'GET'],
            [],
            '/bar',
            'GET'
        );

        $result = $router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame($route2, $result->getMatchedRoute());
    }

    public function testExceptionWhenCacheDirectoryDoesNotExist()
    {
        vfsStream::setup('root');

        $router = new Router(null, null, [
            Router::CONFIG_CACHE_ENABLED => true,
            Router::CONFIG_CACHE_FILE => vfsStream::url('root/dir/cache-file'),
        ]);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => 'GET'],
            [],
            '/foo',
            'GET'
        );

        $this->expectException(InvalidCacheDirectoryException::class);
        $this->expectExceptionMessage('does not exist');
        $router->match($request);
    }

    public function testExceptionWhenCacheDirectoryIsNotWritable()
    {
        $root = vfsStream::setup('root');
        vfsStream::newDirectory('dir', 0)->at($root);

        $router = new Router(null, null, [
            Router::CONFIG_CACHE_ENABLED => true,
            Router::CONFIG_CACHE_FILE => vfsStream::url('root/dir/cache-file'),
        ]);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => 'GET'],
            [],
            '/foo',
            'GET'
        );

        $this->expectException(InvalidCacheDirectoryException::class);
        $this->expectExceptionMessage('is not writable');
        $router->match($request);
    }

    public function testExceptionWhenCacheFileExistsButIsNotWritable()
    {
        $root = vfsStream::setup('root');
        $file = vfsStream::newFile('cache-file', 0)->at($root);

        $router = new Router(null, null, [
            Router::CONFIG_CACHE_ENABLED => true,
            Router::CONFIG_CACHE_FILE => $file->url(),
        ]);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => 'GET'],
            [],
            '/foo',
            'GET'
        );

        $this->expectException(InvalidCacheException::class);
        $this->expectExceptionMessage('is not writable');
        $router->match($request);
    }

    public function testExceptionWhenCacheFileExistsAndIsWritableButContainsNotAnArray()
    {
        $root = vfsStream::setup('root');
        $file = vfsStream::newFile('cache-file')->at($root);
        $file->setContent('<?php return "hello";');

        $this->expectException(InvalidCacheException::class);
        $this->expectExceptionMessage('MUST return an array');
        new Router(null, null, [
            Router::CONFIG_CACHE_ENABLED => true,
            Router::CONFIG_CACHE_FILE => $file->url(),
        ]);
    }

    public function testGetAllAllowedMethods()
    {
        $route1 = new Route('/foo', $this->getMiddleware());
        $route2 = new Route('/bar', $this->getMiddleware(), ['GET', 'POST']);
        $route3 = new Route('/bar', $this->getMiddleware(), ['DELETE']);

        $router = new Router();
        $router->addRoute($route1);
        $router->addRoute($route2);
        $router->addRoute($route3);

        $request = new ServerRequest(
            ['REQUEST_METHOD' => 'HEAD'],
            [],
            '/bar',
            'HEAD'
        );

        $result = $router->match($request);

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailure());
        $this->assertSame(
            ['GET', 'POST', 'DELETE'],
            $result->getAllowedMethods()
        );
    }

    public function testCustomDispatcherCallback()
    {
        $route1 = new Route('/foo', $this->getMiddleware());
        $dispatcher = $this->prophesize(Dispatcher::class);
        $dispatcher
            ->dispatch('GET', '/foo')
            ->shouldBeCalled()
            ->willReturn([
                Dispatcher::FOUND,
                '/foo',
                []
            ]);

        $router = new Router(null, [$dispatcher, 'reveal']);
        $router->addRoute($route1);

        $request = new ServerRequest([], [], '/foo');
        $result = $router->match($request);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
    }
}
