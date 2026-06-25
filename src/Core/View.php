<?php

declare(strict_types=1);

namespace Timer\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Timer\Http\Response;

final class View
{
    private Environment $twig;

    public function __construct(
        string $viewsPath,
        bool $debug,
    ) {
        $loader = new FilesystemLoader($viewsPath);
        $this->twig = new Environment($loader, [
            'cache' => $debug ? false : dirname(__DIR__, 2) . '/var/cache/twig',
            'debug' => $debug,
            'autoescape' => 'html',
        ]);
    }

    public function render(string $template, array $data = []): Response
    {
        $html = $this->twig->render($template, $data);

        return Response::html($html);
    }
}
