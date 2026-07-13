<?php

declare(strict_types=1);

namespace Timer\Support;

use DateTimeImmutable;

final readonly class ReportsFilter
{
    public function __construct(
        public string $month,
        public ?int $projectId,
        public ?int $taskId,
        public string $from,
        public string $to,
        public ?string $projectName,
        public ?string $taskName,
    ) {
    }

    public function monthLabel(string $locale): string
    {
        return Locale::formatMonth(new DateTimeImmutable($this->month . '-01'), $locale);
    }

    public function periodLabel(string $locale): string
    {
        $from = new DateTimeImmutable($this->from);
        $to = new DateTimeImmutable($this->to);
        $firstOfMonth = new DateTimeImmutable($this->month . '-01');
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        if (
            $this->from === $firstOfMonth->format('Y-m-d')
            && $this->to === $lastOfMonth->format('Y-m-d')
        ) {
            return $this->monthLabel($locale);
        }

        if ($this->from === $this->to) {
            return DateHelper::formatCompactDate($from);
        }

        return DateHelper::formatCompactDate($from) . ' – ' . DateHelper::formatCompactDate($to);
    }

    public static function fromDateRange(string $from, string $to): self
    {
        return new self(
            substr($from, 0, 7),
            null,
            null,
            $from,
            $to,
            null,
            null,
        );
    }

    public function queryString(): string
    {
        $params = ['month' => $this->month];

        if ($this->projectId !== null) {
            $params['project_id'] = (string) $this->projectId;
        }

        if ($this->taskId !== null) {
            $params['task_id'] = (string) $this->taskId;
        }

        return http_build_query($params);
    }

    public function exportFilenameStem(): string
    {
        $firstOfMonth = new DateTimeImmutable($this->month . '-01');
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        if (
            $this->from !== $firstOfMonth->format('Y-m-d')
            || $this->to !== $lastOfMonth->format('Y-m-d')
        ) {
            return 'zeitbericht-' . $this->from . '-' . $this->to;
        }

        $parts = ['zeitbericht', $this->month];

        if ($this->projectName !== null) {
            $parts[] = $this->slugify($this->projectName);
        }

        if ($this->taskName !== null) {
            $parts[] = $this->slugify($this->taskName);
        }

        return implode('-', $parts);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? substr($value, 0, 40) : 'filter';
    }
}
