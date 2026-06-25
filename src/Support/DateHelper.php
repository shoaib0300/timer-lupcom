<?php

declare(strict_types=1);

namespace Timer\Support;

use DateTimeImmutable;

final class DateHelper
{
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
}
