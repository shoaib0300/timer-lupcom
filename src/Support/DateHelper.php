<?php

declare(strict_types=1);

namespace Timer\Support;

use DateTimeImmutable;
use DateTimeZone;

final class DateHelper
{
    public static function appTimezone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }

    public static function parseDateTime(string $value): DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return new DateTimeImmutable('now', self::appTimezone());
        }

        if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value) === 1) {
            return (new DateTimeImmutable($value))->setTimezone(self::appTimezone());
        }

        return new DateTimeImmutable($value, self::appTimezone());
    }

    public static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }

    public static function todayString(): string
    {
        return self::today()->format('Y-m-d');
    }

    public static function parseDateOnly(string $input): ?DateTimeImmutable
    {
        if ($input === '') {
            return self::today();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input);

        return $date && $date->format('Y-m-d') === $input ? $date : null;
    }

    public static function isFutureDate(string $dateString): bool
    {
        return $dateString > self::todayString();
    }

    private const array MONTH_ABBR = [
        'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN',
        'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC',
    ];

    public static function formatCompactDate(\DateTimeInterface|string $value): string
    {
        $date = is_string($value)
            ? self::parseDateTime($value)
            : DateTimeImmutable::createFromInterface($value)->setTimezone(self::appTimezone());

        $month = self::MONTH_ABBR[(int) $date->format('n') - 1];

        return $date->format('d') . '-' . $month . '-' . $date->format('y');
    }

    public static function formatCompactDateTime(\DateTimeInterface|string $value): string
    {
        $date = is_string($value)
            ? self::parseDateTime($value)
            : DateTimeImmutable::createFromInterface($value)->setTimezone(self::appTimezone());

        return self::formatCompactDate($date) . ' ' . $date->format('H:i');
    }
}
