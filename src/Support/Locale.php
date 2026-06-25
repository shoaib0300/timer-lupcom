<?php

declare(strict_types=1);

namespace Timer\Support;

final class Locale
{
    public const string DEFAULT = 'en';

    /** @var list<string> */
    public const array SUPPORTED = ['en', 'de'];

    public static function fromAcceptLanguage(?string $header): string
    {
        if ($header === null || $header === '') {
            return self::DEFAULT;
        }

        foreach (explode(',', $header) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0]));
            if ($tag === '') {
                continue;
            }

            $primary = explode('-', $tag)[0];
            if ($primary === 'de') {
                return 'de';
            }

            if ($primary === 'en') {
                return 'en';
            }
        }

        return self::DEFAULT;
    }

    public static function formatMonth(\DateTimeInterface $date, string $locale): string
    {
        return self::format($date, $locale, 'MMMM y') ?? $date->format('F Y');
    }

    public static function formatDay(\DateTimeInterface $date, string $locale): string
    {
        return self::format($date, $locale, 'EEEE, d MMMM y')
            ?? $date->format('l, j F Y');
    }

    private static function format(\DateTimeInterface $date, string $locale, string $pattern): ?string
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return null;
        }

        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            null,
            null,
            $pattern,
        );

        $formatted = $formatter->format($date);

        return is_string($formatted) && $formatted !== '' ? $formatted : null;
    }
}
