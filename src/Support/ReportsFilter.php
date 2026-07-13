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
