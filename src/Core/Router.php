<?php

declare(strict_types=1);

namespace Timer\Core;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\Dispatcher\GroupCountBased as GroupCountBasedDispatcher;
use Timer\Http\Request;
use Timer\Http\Response;

final class Router
{
    public function __construct(
        private readonly \Closure $routeDefinition,
    ) {
    }

    public function dispatch(Request $request, Application $app): Response
    {
        $dispatcher = $this->createDispatcher();
        $routeInfo = $dispatcher->dispatch($request->method(), $request->path());

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                return Response::html('Page not found', 404);

            case Dispatcher::METHOD_NOT_ALLOWED:
                return Response::html('Method not allowed', 405);

            case Dispatcher::FOUND:
                [$handler, $vars] = [$routeInfo[1], $routeInfo[2]];
                [$class, $method] = $handler;

                $controller = new $class($app);
                $result = $controller->$method($request, ...array_values($vars));

                if ($result instanceof Response) {
                    return $result;
                }

                return Response::html((string) $result);

            default:
                return Response::html('Unexpected routing error', 500);
        }
    }

    private function createDispatcher(): Dispatcher
    {
        return new GroupCountBasedDispatcher(
            (new RouteCollector(new Std(), new GroupCountBased()))->addGroup('', $this->routeDefinition)->getData(),
        );
    }
}
