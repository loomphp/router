<?php

declare(strict_types=1);

namespace LoomTest\Router;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\MiddlewareInterface;
use Loom\Router\Exception\RuntimeException;
use Loom\Router\Router;
use Loom\Router\Route;

class UriGeneratorTest extends TestCase
{
    private $fastRouter;
    private $dispatcher;
    private $dispatchCallback;
    private $router;

    public function provideRoutes()
    {
        return [
            'test_param_regex'       => '/test/{param:\d+}',
            'test_param_regex_limit' => '/test/{ param : \d{1,9} }',
            'test_optional'          => '/test[opt]',
            'test_optional_param'    => '/test[/{param}]',
            'param_and_opt'          => '/{param}[opt]',
            'test_double_opt'        => '/test[/{param}[/{id:[0-9]+}]]',
            'empty'                  => '',
            'optional_text'          => '[test]',
            'root_and_text'          => '/{foo-bar}',
            'root_and_regex'         => '/{_foo:.*}',
        ];
    }

    public function provideRouteTests()
    {
        return [
            ['/test', [], '/test', null],

            ['/test/{param}', ['param' => 'foo'], '/test/foo', null],
            [
                '/test/{param}',
                ['id' => 'foo'],
                RuntimeException::class,
                'expects at least parameter values for',
            ],

            ['/te{ param }st', ['param' => 'foo'], '/tefoost', null],

            ['/test/{param1}/test2/{param2}', ['param1' => 'foo', 'param2' => 'bar'], '/test/foo/test2/bar', null],

            ['/test/{param:\d+}', ['param' => 1], '/test/1', null],

            ['/test/{ param : \d{1,9} }', ['param' => 1], '/test/1', null],
            ['/test/{ param : \d{1,9} }', ['param' => 123456789], '/test/123456789', null],
            ['/test/{ param : \d{1,9} }', ['param' => 0], '/test/0', null],
            [
                '/test/{ param : \d{1,9} }',
                ['param' => 1234567890],
                RuntimeException::class,
                'Parameter value for [param] did not match the regex `\d{1,9}`',
            ],

            ['/test[opt]', [], '/testopt', null],

            ['/test[/{param}]', [], '/test', null],
            ['/test[/{param}]', ['param' => 'foo'], '/test/foo', null],

            ['/{param}[opt]', ['param' => 'foo'], '/fooopt', null],

            ['/test[/{param}[/{id:[0-9]+}]]', [], '/test', null],
            ['/test[/{param}[/{id:[0-9]+}]]', ['param' => 'foo'], '/test/foo', null],
            ['/test[/{param}[/{id:[0-9]+}]]', ['param' => 'foo', 'id' => 1], '/test/foo/1', null],
            ['/test[/{param}[/{id:[0-9]+}]]', ['id' => 1], '/test', null],
            [
                '/test[/{param}[/{id:[0-9]+}]]',
                ['param' => 'foo', 'id' => 'foo'],
                RuntimeException::class,
                'Parameter value for [id] did not match the regex `[0-9]+`',
            ],

            ['', [], '', null],

            ['[test]', [], 'test', null],

            ['/{foo-bar}', ['foo-bar' => 'bar'], '/bar', null],

            ['/{_foo:.*}', ['_foo' => 'bar'], '/bar', null],
        ];
    }

    protected function setUp()
    {
        $this->fastRouter       = $this->prophesize(RouteCollector::class);
        $this->dispatcher       = $this->prophesize(Dispatcher::class);
        $this->dispatchCallback = function () {
            return $this->dispatcher->reveal();
        };

        $this->router = new Router(
            $this->fastRouter->reveal(),
            $this->dispatchCallback
        );
    }

    private function getMiddleware() : MiddlewareInterface
    {
        return $this->prophesize(MiddlewareInterface::class)->reveal();
    }

    /**
     * @dataProvider provideRouteTests
     */
    public function testRoutes($path, $substitutions, $expected, $message)
    {
        $this->router->addRoute(new Route($path, $this->getMiddleware(), ['GET'], 'foo'));

        if ($message !== null) {
            $this->expectException($expected);
            $this->expectExceptionMessage($message);

            $this->router->generateUri('foo', $substitutions);

            return;
        }

        $this->assertEquals($expected, $this->router->generateUri('foo', $substitutions));

        $substitutions['extra'] = 'parameter';
        $this->assertEquals($expected, $this->router->generateUri('foo', $substitutions));
    }
}
