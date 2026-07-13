<?php

declare(strict_types=1);

namespace Timer\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Timer\Http\Response;
use Timer\Support\DateHelper;
use Timer\Support\TextFormatter;
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
        $this->twig->addFunction(new TwigFunction('format_compact_date', [DateHelper::class, 'formatCompactDate']));
        $this->twig->addFunction(new TwigFunction('format_compact_datetime', [DateHelper::class, 'formatCompactDateTime']));
        $this->twig->addFunction(new TwigFunction('format_rich_text', [TextFormatter::class, 'formatRichText'], ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('user_initials', [TextFormatter::class, 'initials']));
        $this->twig->addFunction(new TwigFunction('trans', function (string $key, array $params = []): string {
            return $this->translator->trans($key, $params);
        }));
    }

    public function render(string $template, array $data = []): Response
    {
        return Response::html($this->renderToString($template, $data));
    }

    public function renderToString(string $template, array $data = []): string
    {
        return $this->twig->render($template, array_merge([
            'locale' => $this->translator->locale(),
            'js_translations' => $this->translator->jsStrings(),
        ], $data));
    }
}
