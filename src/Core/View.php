<?php

declare(strict_types=1);

namespace Timer\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Timer\Http\Response;
use Timer\Support\TimeFormatter;
use Timer\Support\Translator;

final class View
{
    private Environment $twig;

    public function __construct(
        string $viewsPath,
        bool $debug,
        private readonly Translator $translator,
    ) {
        $loader = new FilesystemLoader($viewsPath);
        $this->twig = new Environment($loader, [
            'cache' => $debug ? false : dirname(__DIR__, 2) . '/var/cache/twig',
            'debug' => $debug,
            'autoescape' => 'html',
        ]);

        $this->twig->addFunction(new TwigFunction('format_time', [TimeFormatter::class, 'secondsToHuman']));
        $this->twig->addFunction(new TwigFunction('format_clock', [TimeFormatter::class, 'secondsToClock']));
        $this->twig->addFunction(new TwigFunction('trans', function (string $key, array $params = []): string {
            return $this->translator->trans($key, $params);
        }));
    }

    public function render(string $template, array $data = []): Response
    {
        $html = $this->twig->render($template, array_merge([
            'locale' => $this->translator->locale(),
            'js_translations' => $this->translator->jsStrings(),
        ], $data));

        return Response::html($html);
    }
}
