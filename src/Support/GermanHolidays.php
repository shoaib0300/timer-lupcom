<?php

declare(strict_types=1);

namespace Timer\Support;

use DateTimeImmutable;

/**
 * German public holidays by Bundesland (national + state-specific).
 */
final class GermanHolidays
{
  /** @var array<string, string> */
    public const STATES = [
        'BW' => 'Baden-Württemberg',
        'BY' => 'Bayern',
        'BE' => 'Berlin',
        'BB' => 'Brandenburg',
        'HB' => 'Bremen',
        'HH' => 'Hamburg',
        'HE' => 'Hessen',
        'MV' => 'Mecklenburg-Vorpommern',
        'NI' => 'Niedersachsen',
        'NW' => 'Nordrhein-Westfalen',
        'RP' => 'Rheinland-Pfalz',
        'SL' => 'Saarland',
        'SN' => 'Sachsen',
        'ST' => 'Sachsen-Anhalt',
        'SH' => 'Schleswig-Holstein',
        'TH' => 'Thüringen',
    ];

    /**
     * @return array<string, string> date => name
     */
    public static function forState(int $year, string $stateCode): array
    {
        $stateCode = strtoupper($stateCode);
        $easter = self::easter($year);
        $holidays = [];

        self::add($holidays, self::fixed($year, 1, 1), 'Neujahr');
        self::add($holidays, $easter->modify('-2 days'), 'Karfreitag');
        self::add($holidays, $easter->modify('+1 day'), 'Ostermontag');
        self::add($holidays, self::fixed($year, 5, 1), 'Tag der Arbeit');
        self::add($holidays, $easter->modify('+39 days'), 'Christi Himmelfahrt');
        self::add($holidays, $easter->modify('+50 days'), 'Pfingstmontag');
        self::add($holidays, self::fixed($year, 10, 3), 'Tag der Deutschen Einheit');
        self::add($holidays, self::fixed($year, 12, 25), '1. Weihnachtstag');
        self::add($holidays, self::fixed($year, 12, 26), '2. Weihnachtstag');

        if (in_array($stateCode, ['BW', 'BY', 'ST'], true)) {
            self::add($holidays, self::fixed($year, 1, 6), 'Heilige Drei Könige');
        }

        if (in_array($stateCode, ['BW', 'BY', 'HE', 'NW', 'RP', 'SL', 'SN', 'TH'], true)) {
            self::add($holidays, $easter->modify('+60 days'), 'Fronleichnam');
        }

        if ($stateCode === 'SL') {
            self::add($holidays, self::fixed($year, 8, 15), 'Mariä Himmelfahrt');
        }

        if (in_array($stateCode, ['BB', 'HB', 'HH', 'MV', 'NI', 'SN', 'SH', 'ST', 'TH'], true)) {
            self::add($holidays, self::fixed($year, 10, 31), 'Reformationstag');
        }

        if (in_array($stateCode, ['BW', 'BY', 'NW', 'RP', 'SL'], true)) {
            self::add($holidays, self::fixed($year, 11, 1), 'Allerheiligen');
        }

        if ($stateCode === 'SN') {
            self::add($holidays, self::bussUndBettag($year), 'Buß- und Bettag');
        }

        ksort($holidays);

        return $holidays;
    }

    /**
     * @return array<string, string> date => name
     */
    public static function forYear(int $year): array
    {
        return self::forState($year, 'BE');
    }

    private static function easter(int $year): DateTimeImmutable
    {
        return (new DateTimeImmutable('UTC'))
            ->setTimestamp(easter_date($year))
            ->setTimezone(new \DateTimeZone('Europe/Berlin'))
            ->setTime(0, 0);
    }

    private static function fixed(int $year, int $month, int $day): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    private static function bussUndBettag(int $year): DateTimeImmutable
    {
        $nov23 = new DateTimeImmutable(sprintf('%d-11-23', $year));
        $dow = (int) $nov23->format('N');

        return $nov23->modify('-' . ($dow + 4) . ' days');
    }

    /** @param array<string, string> $holidays */
    private static function add(array &$holidays, DateTimeImmutable $date, string $name): void
    {
        $holidays[$date->format('Y-m-d')] = $name;
    }
}
