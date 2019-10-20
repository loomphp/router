<?php

declare(strict_types=1);

namespace LoomTest\Router;

use Loom\Router\RouteInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Loom\Router\RouteResult;

class RouteResultTest extends TestCase
{

    public function testFromRouteWithParams()
    {
        $route = $this->prophesize(RouteInterface::class);

        $result = RouteResult::fromRoute($route->reveal(), ['foo' => 'bar']);
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame($route->reveal(), $result->getMatchedRoute());
        $this->assertSame(['foo' => 'bar'], $result->getMatchedParams());

        $route->getMethods()->willReturn(['HEAD', 'OPTIONS', 'GET']);
        $this->assertEquals(['HEAD', 'OPTIONS', 'GET'], $result->getAllowedMethods());
    }

    public function testFromRouteRouteMatchedName()
    {
        $route = $this->prophesize(RouteInterface::class);
        $route->getName()->willReturn('foo');
        $result = RouteResult::fromRoute($route->reveal());
        $this->assertSame('foo', $result->getMatchedRouteName());
    }

    public function testSuccessfulResultProcessedAsMiddlewareDelegatesToRoute()
    {
        $request = $this->prophesize(ServerRequestInterface::class)->reveal();
        $response = $this->prophesize(ResponseInterface::class)->reveal();
        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler->handle($request)->shouldNotBeCalled();

        $route = $this->prophesize(RouteInterface::class);
        $route->process($request, $handler)->willReturn($response);

        $result = RouteResult::fromRoute($route->reveal());

        $this->assertSame($response, $result->process($request, $handler->reveal()));
    }

    public function testRouteFailureResult()
    {
        $result = RouteResult::fromRouteFailure([]);
        $this->assertFalse($result->getMatchedRouteName());
        $this->assertSame([], $result->getAllowedMethods());
        $this->assertTrue($result->isMethodFailure());
    }

    public function testRouteFailureDefaultHttpMethods()
    {
        $result = RouteResult::fromRouteFailure(null);
        $this->assertSame(null, $result->getAllowedMethods());
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isMethodFailure());
    }

    public function testRouteMethodFailure()
    {
        $result = RouteResult::fromRouteFailure(['GET']);
        $this->assertTrue($result->isMethodFailure());
    }

    public function testRouteSuccessMethodFailure()
    {
        $params = ['foo' => 'bar'];
        $route = $this->prophesize(RouteInterface::class);
        $result = RouteResult::fromRoute($route->reveal(), $params);

        $this->assertFalse($result->isMethodFailure());
    }

    public function testFailureResultProcessedAsMiddlewareDelegatesToHandler()
    {
        $request = $this->prophesize(ServerRequestInterface::class)->reveal();
        $response = $this->prophesize(ResponseInterface::class)->reveal();
        $handler = $this->prophesize(RequestHandlerInterface::class);
        $handler->handle($request)->willReturn($response);

        $result = RouteResult::fromRouteFailure([]);

        $this->assertSame($response, $result->process($request, $handler->reveal()));
    }
}
