<?php

declare(strict_types=1);

namespace Timer\Support;

final class TextFormatter
{
    public static function formatRichText(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return (string) preg_replace(
            '~\b((?:https?)://[^\s<]+)~iu',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $escaped,
        );
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return '?';
        }

        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last);
    }
}
