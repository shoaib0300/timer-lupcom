<?php

declare(strict_types=1);

namespace Timer\Repositories;

use PDO;

final class AttendanceHolidayRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return list<array{holiday_date: string, override_type: string, name: ?string}>
     */
    public function overrides(string $countryCode, string $stateCode, string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT holiday_date, override_type, name
            FROM attendance_holiday_overrides
            WHERE country_code = ? AND state_code = ?
              AND holiday_date >= ? AND holiday_date <= ?
            ORDER BY holiday_date',
        );
        $stmt->execute([$countryCode, $stateCode, $from, $to]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

  /**
   * @return list<array{holiday_date: string, override_type: string, name: ?string}>
   */
    public function overridesForYear(string $countryCode, string $stateCode, int $year): array
    {
        return $this->overrides(
            $countryCode,
            $stateCode,
            sprintf('%d-01-01', $year),
            sprintf('%d-12-31', $year),
        );
    }

    public function addManual(string $date, string $countryCode, string $stateCode, string $name): void
    {
        $this->removeOverride($date, $countryCode, $stateCode, 'add');
        $stmt = $this->pdo->prepare(
            'INSERT INTO attendance_holiday_overrides (holiday_date, country_code, state_code, override_type, name)
            VALUES (?, ?, ?, \'add\', ?)',
        );
        $stmt->execute([$date, $countryCode, $stateCode, $name]);
    }

    public function removeBuiltin(string $date, string $countryCode, string $stateCode): void
    {
        $this->removeOverride($date, $countryCode, $stateCode, 'remove');
        $stmt = $this->pdo->prepare(
            'INSERT INTO attendance_holiday_overrides (holiday_date, country_code, state_code, override_type, name)
            VALUES (?, ?, ?, \'remove\', NULL)',
        );
        $stmt->execute([$date, $countryCode, $stateCode]);
    }

    public function restoreBuiltin(string $date, string $countryCode, string $stateCode): void
    {
        $this->removeOverride($date, $countryCode, $stateCode, 'remove');
    }

    public function deleteManual(string $date, string $countryCode, string $stateCode): void
    {
        $this->removeOverride($date, $countryCode, $stateCode, 'add');
    }

    private function removeOverride(string $date, string $countryCode, string $stateCode, string $type): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM attendance_holiday_overrides
            WHERE holiday_date = ? AND country_code = ? AND state_code = ? AND override_type = ?',
        );
        $stmt->execute([$date, $countryCode, $stateCode, $type]);
    }
}
