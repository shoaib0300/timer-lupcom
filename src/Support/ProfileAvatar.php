<?php

declare(strict_types=1);

namespace Timer\Support;

final class ProfileAvatar
{
    private const string UPLOAD_DIR = '/uploads/avatars';

    public static function uploadDir(): string
    {
        return dirname(__DIR__, 2) . '/public' . self::UPLOAD_DIR;
    }

    public static function publicUrl(?string $filename): ?string
    {
        if ($filename === null || $filename === '') {
            return null;
        }

        $path = self::uploadDir() . '/' . $filename;
        if (!is_file($path)) {
            return null;
        }

        return self::UPLOAD_DIR . '/' . rawurlencode($filename) . '?v=' . (string) filemtime($path);
    }

    public static function deleteFile(?string $filename): void
    {
        if ($filename === null || $filename === '') {
            return;
        }

        $path = self::uploadDir() . '/' . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }

    public static function storeUpload(int $userId, array $file): string
    {
        if (!is_readable($file['tmp_name']) || filesize($file['tmp_name']) === 0) {
            throw new \InvalidArgumentException('Avatar file is empty.');
        }

        if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new \InvalidArgumentException('Avatar must be 2 MB or smaller.');
        }

        $mime = mime_content_type($file['tmp_name']) ?: '';
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new \InvalidArgumentException('Avatar must be a JPG, PNG, or WebP image.'),
        };

        if (!is_dir(self::uploadDir()) && !mkdir(self::uploadDir(), 0775, true) && !is_dir(self::uploadDir())) {
            throw new \RuntimeException('Could not create avatar upload directory.');
        }

        $filename = 'user-' . $userId . '.' . $extension;
        $target = self::uploadDir() . '/' . $filename;

        foreach (['jpg', 'png', 'webp'] as $ext) {
            $candidate = self::uploadDir() . '/user-' . $userId . '.' . $ext;
            if ($candidate !== $target && is_file($candidate)) {
                unlink($candidate);
            }
        }

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new \RuntimeException('Could not save avatar.');
        }

        return $filename;
    }
}
