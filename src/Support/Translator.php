<?php

declare(strict_types=1);

namespace Timer\Support;

final class Translator
{
    /** @var array<string, string> */
    private array $messages;

    public function __construct(
        private readonly string $locale,
        string $langPath,
    ) {
        $fallback = require $langPath . '/en.php';
        $messages = $locale === 'en'
            ? $fallback
            : array_merge($fallback, require $langPath . '/' . $locale . '.php');

        $this->messages = $messages;
    }

    public static function fromRequest(string $langPath, ?string $acceptLanguage): self
    {
        $locale = Locale::fromAcceptLanguage($acceptLanguage);

        return new self($locale, $langPath);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string, scalar> $params */
    public function trans(string $key, array $params = []): string
    {
        $message = $this->messages[$key] ?? $key;

        foreach ($params as $name => $value) {
            $message = str_replace(':' . $name, (string) $value, $message);
        }

        return $message;
    }

    /** @return array<string, string> */
    public function jsStrings(): array
    {
        $keys = array_filter(
            array_keys($this->messages),
            static fn (string $key): bool => str_starts_with($key, 'js.'),
        );

        $strings = [];
        foreach ($keys as $key) {
            $strings[substr($key, 3)] = $this->messages[$key];
        }

        return $strings;
    }
}
