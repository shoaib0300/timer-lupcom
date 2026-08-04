<?php

declare(strict_types=1);

namespace Timer\Support;

final class TextFormatter
{
    /**
     * Render Planio/Redmine textile-ish text into safe HTML for display.
     */
    public static function formatRichText(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return '';
        }

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = explode("\n", $escaped);
        $html = [];
        $listOpen = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^\*\s+(.+)$/u', $trimmed, $matches) === 1) {
                if (!$listOpen) {
                    $html[] = '<ul>';
                    $listOpen = true;
                }
                $html[] = '<li>' . self::inlineFormat($matches[1]) . '</li>';
                continue;
            }

            if ($listOpen) {
                $html[] = '</ul>';
                $listOpen = false;
            }

            if ($trimmed === '') {
                $html[] = '';
                continue;
            }

            $html[] = '<p>' . self::inlineFormat($trimmed) . '</p>';
        }

        if ($listOpen) {
            $html[] = '</ul>';
        }

        return implode('', $html);
    }

    private static function inlineFormat(string $text): string
    {
        // Textile-style bold/italic used by Planio descriptions.
        $text = (string) preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<!_)_([^_\n]+)_(?!_)/u', '<em>$1</em>', $text);

        return (string) preg_replace(
            '~\b((?:https?)://[^\s<]+)~iu',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $text,
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
