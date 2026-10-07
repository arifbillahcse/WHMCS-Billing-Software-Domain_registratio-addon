<?php

namespace NsManager;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Tiny template renderer plus the formatting helpers the admin templates share.
 * Templates are plain PHP files in ../templates and receive an `$e` escaper.
 */
class View
{
    public const STALE_HOURS = 24;

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = []): string
    {
        $file = __DIR__ . '/../templates/' . $template . '.php';
        $e = static fn ($value): string => View::e($value);
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            include $file;
        } catch (\Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }
        return (string) ob_get_clean();
    }

    /**
     * @param mixed $value
     */
    public static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Seconds since a DB timestamp (0 for empty or unparseable values).
     */
    public static function ageSeconds(?string $timestamp): int
    {
        $time = $timestamp ? strtotime($timestamp) : false;
        return $time ? max(0, time() - $time) : 0;
    }

    public static function ageText(?string $timestamp): string
    {
        $seconds = self::ageSeconds($timestamp);
        if ($seconds < 3600) {
            return max(1, (int) floor($seconds / 60)) . 'm';
        }
        if ($seconds < 86400) {
            return (int) floor($seconds / 3600) . 'h';
        }
        return (int) floor($seconds / 86400) . 'd ' . (int) floor(($seconds % 86400) / 3600) . 'h';
    }

    public static function isStale(?string $timestamp): bool
    {
        return self::ageSeconds($timestamp) >= self::STALE_HOURS * 3600;
    }

    /**
     * Only http(s) URLs may become links; anything else (javascript:, data:) is dropped.
     */
    public static function safeUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '' || !preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        return $url;
    }
}
