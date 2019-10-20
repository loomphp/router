<?php

declare(strict_types=1);

namespace LoomTest\Router;

use Loom\Router\Exception\InvalidArgumentException;
use Loom\Router\Route;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RouteTest extends TestCase
{
    /**
     * @var MiddlewareInterface
     */
    private $middleware;

    public function setUp()
    {
        $this->middleware = $this->prophesize(MiddlewareInterface::class)->reveal();
    }

    public function testRouteConstructor()
    {
        $methods = ['GET', 'POST'];
        $path = '/blog/{name}';
        $name = 'blog';
        $route = new Route($path, $this->middleware, $methods, $name);

        $this->assertEquals($path, $route->getPath());
        $this->assertEquals($this->middleware, $route->getMiddleware());
        $this->assertEquals($methods, $route->getMethods());
        $this->assertEquals($name, $route->getName());
        $this->assertSame([], $route->getOptions());
    }

    public function testRouteEmptyArrayMethodsRaiseInvalidArgumentException()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');
        new Route('/foo', $this->middleware, []);
    }

    public function invalidHttpMethodsProvider()
    {
        return [
            [[123]],
            [[123, 456]],
            [['@@@']],
            [['@@@', '@@@']],
        ];
    }

    /**
     * @dataProvider invalidHttpMethodsProvider
     */
    public function testRouteThrowsExceptionOnInvalidHttpMethods(array $invalidHttpMethods)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('One or more HTTP methods are invalid');

        new Route('/test', $this->middleware, $invalidHttpMethods);
    }

    public function testRouteDefaultNameWithPath()
    {
        $route = new Route('/test', $this->middleware);
        $this->assertSame('/test', $route->getName());
    }

    public function testRouteDefaultNameWithGET()
    {
        $route = new Route('/test', $this->middleware, ['GET']);
        $this->assertSame('/test^GET', $route->getName());
    }

    public function testRouteDefaultNameWithGetAndPost()
    {
        $route = new Route('/test', $this->middleware, ['GET', 'POST']);
        $this->assertSame('/test^GET:POST', $route->getName());
    }

    public function testRouteMiddlewareProcess()
    {
        $request = $this->prophesize(ServerRequestInterface::class)->reveal();
        $handler = $this->prophesize(RequestHandlerInterface::class)->reveal();
        $response = $this->prophesize(ResponseInterface::class)->reveal();
        $middleware = $this->prophesize(MiddlewareInterface::class);
        $middleware->process($request, $handler)->willReturn($response);

        $route = new Route('/foo', $middleware->reveal(), ['GET']);
        $this->assertSame($response, $route->process($request, $handler));
    }

    public function testRoutePathCanBeChanged()
    {
        $route = new Route('/', $this->middleware, ['GET']);
        $route->setPath('/blog/{name}');

        $this->assertEquals('/blog/{name}', $route->getPath());
    }

    public function testRouteNameCanBeChanged()
    {
        $route = new Route('/', $this->middleware, ['GET'], 'foo');
        $route->setName('bar');

        $this->assertEquals('bar', $route->getName());
    }

    public function testRouteAllowsMethod()
    {
        $methods = ['GET', 'POST'];
        $route = new Route('/foo', $this->middleware, $methods);
        $this->assertTrue($route->isAllowedMethod('GET'));
        $this->assertTrue($route->isAllowedMethod('POST'));
        $this->assertFalse($route->isAllowedMethod('PATCH'));
        $this->assertFalse($route->isAllowedMethod('DELETE'));
    }

    public function testRouteHeadMethodIsNotAllowedByDefault()
    {
        $route = new Route('/foo', $this->middleware, ['GET']);
        $this->assertFalse($route->isAllowedMethod('HEAD'));
    }

    public function testRouteOptionsMethodIsNotAllowedByDefault()
    {
        $route = new Route('/foo', $this->middleware, ['GET']);
        $this->assertFalse($route->isAllowedMethod('OPTIONS'));
    }

    public function testRouteSetAndGetOptions()
    {
        $options = ['foo' => 'bar'];
        $route = new Route('/foo', $this->middleware);
        $route->setOptions($options);
        $this->assertSame($options, $route->getOptions());
    }
}
