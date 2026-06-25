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
                return Response::html($app->translator()->trans('error.page_not_found'), 404);

            case Dispatcher::METHOD_NOT_ALLOWED:
                return Response::html($app->translator()->trans('error.method_not_allowed'), 405);

            case Dispatcher::FOUND:
                [$handler, $vars] = [$routeInfo[1], $routeInfo[2]];
                [$class, $method] = $handler;

                $controller = new $class($app);
                $result = $controller->$method($request, ...$this->coerceRouteArgs($vars));

                if ($result instanceof Response) {
                    return $result;
                }

                return Response::html((string) $result);

            default:
                return Response::html($app->translator()->trans('error.server_error'), 500);
        }
    }

    private function createDispatcher(): Dispatcher
    {
        $collector = new RouteCollector(new Std(), new GroupCountBased());
        ($this->routeDefinition)($collector);

        return new GroupCountBasedDispatcher($collector->getData());
    }

    /** @param array<string, string> $vars */
    private function coerceRouteArgs(array $vars): array
    {
        return array_map(
            static fn (string $value): int|string => preg_match('/^\d+$/', $value) ? (int) $value : $value,
            array_values($vars),
        );
    }
}
