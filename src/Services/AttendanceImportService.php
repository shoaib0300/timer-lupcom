<?php

declare(strict_types=1);

namespace Timer\Services;

use Timer\Models\AttendanceDay;
use Timer\Repositories\AttendanceDayRepository;

final class AttendanceImportService
{
    public const MODE_MERGE = 'merge';
    public const MODE_REPLACE = 'replace';

    public function __construct(
        private readonly AttendanceDayRepository $days,
        private readonly LupcomTimetableParser $parser,
        private readonly LupcomXlsxTimetableReader $xlsxReader,
    ) {
    }

    /**
     * @return array{
     *     imported: int,
     *     cleared: int,
     *     kept: int,
     *     dates: list<string>,
     * }
     */
    public function importFile(string $path, string $originalName, string $mode): array
    {
        $parsedDays = $this->parseFile($path, $originalName);

        if ($parsedDays === []) {
            throw new \InvalidArgumentException('no_days_found');
        }

        if ($mode === self::MODE_REPLACE) {
            $this->days->deleteAll();
        }

        $imported = 0;
        $cleared = 0;

        foreach ($parsedDays as $day) {
            $date = $day['date'];
            $morningStart = $day['morning_start'];
            $morningEnd = $day['morning_end'];
            $afternoonStart = $day['afternoon_start'];
            $afternoonEnd = $day['afternoon_end'];

            $hasTimes = $morningStart !== null
                || $morningEnd !== null
                || $afternoonStart !== null
                || $afternoonEnd !== null;

            if ($mode === self::MODE_MERGE) {
                $existing = $this->days->find($date);
                if ($existing !== null && in_array($existing->dayType, [AttendanceDay::TYPE_VACATION, AttendanceDay::TYPE_SICK], true)) {
                    continue;
                }
            }

            if (!$hasTimes) {
                $this->days->delete($date);
                ++$cleared;
                continue;
            }

            $this->days->save(
                $date,
                AttendanceDay::TYPE_WORK,
                $morningStart,
                $morningEnd,
                $afternoonStart,
                $afternoonEnd,
            );
            ++$imported;
        }

        return [
            'imported' => $imported,
            'cleared' => $cleared,
            'kept' => 0,
            'dates' => array_map(static fn (array $day): string => $day['date'], $parsedDays),
        ];
    }

    /**
     * @return list<array{
     *     date: string,
     *     morning_start: ?string,
     *     morning_end: ?string,
     *     afternoon_start: ?string,
     *     afternoon_end: ?string,
     * }>
     */
    private function parseFile(string $path, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx' => $this->parser->parseRows($this->xlsxReader->rows($path)),
            'csv', 'txt' => $this->parseCsvFile($path),
            default => throw new \InvalidArgumentException('unsupported_format'),
        };
    }

    /** @return list<array<string, mixed>> */
    private function parseCsvFile(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new \InvalidArgumentException('empty_file');
        }

        return $this->parser->parse($content);
    }
}
