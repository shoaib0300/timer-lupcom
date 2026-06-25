<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Core\Application;
use Timer\Http\Request;
use Timer\Http\Response;

abstract class BaseController
{
    public function __construct(
        protected readonly Application $app,
    ) {
    }

    protected function view(string $template, array $data = []): Response
    {
        return $this->app->view()->render($template, $data);
    }

    /** @param array<string, scalar> $params */
    protected function trans(string $key, array $params = []): string
    {
        return $this->app->translator()->trans($key, $params);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($path);
    }

    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }
}
