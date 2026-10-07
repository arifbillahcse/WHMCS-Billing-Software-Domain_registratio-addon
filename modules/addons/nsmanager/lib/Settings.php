<?php

namespace NsManager;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Reads the addon's configuration (Setup > Addon Modules) straight from
 * tbladdonmodules so it works from the registrar module and cron as well as
 * from the admin page. Unsaved settings fall back to DEFAULTS.
 */
class Settings
{
    /** Keep in sync with the 'Default' values in nsmanager_config(). */
    public const DEFAULTS = [
        'notify_admin_email' => 'on',
        'notify_ticket' => '',
        'ticket_dept_id' => '',
        'ticket_priority' => 'Medium',
        'notify_telegram' => '',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'notify_slack' => '',
        'slack_webhook_url' => '',
        'notify_customer_applied' => 'on',
        'notify_customer_failed' => 'on',
        'auto_verify_dns' => 'on',
        'reminder_hours' => '24',
        'rate_limit_per_hour' => '5',
    ];

    /** @var array<string, string>|null */
    private static $cache = null;

    public static function reset(): void
    {
        self::$cache = null;
    }

    public static function get(string $key): string
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                $rows = Capsule::table('tbladdonmodules')->where('module', 'nsmanager')->pluck('value', 'setting');
                foreach ($rows as $setting => $value) {
                    self::$cache[(string) $setting] = (string) $value;
                }
            } catch (\Throwable $e) {
                // No settings table/rows yet: defaults apply.
            }
        }
        return trim(self::$cache[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    /**
     * Numeric setting; blank or non-numeric falls back to the default, never below 0.
     */
    public static function int(string $key): int
    {
        $value = self::get($key);
        if ($value === '' || !is_numeric($value)) {
            $value = self::DEFAULTS[$key] ?? '0';
        }
        return max(0, (int) $value);
    }

    public static function bool(string $key): bool
    {
        return in_array(strtolower(self::get($key)), ['on', '1', 'yes', 'true'], true);
    }

    /**
     * A password-type setting. WHMCS stores these encrypted; try decrypting and
     * keep whichever form passes $valid, so it works if a version stores plain text.
     */
    public static function secret(string $key, callable $valid): string
    {
        $raw = self::get($key);
        if ($raw === '') {
            return '';
        }
        if (function_exists('decrypt')) {
            $plain = trim((string) decrypt($raw));
            if ($plain !== '' && $valid($plain)) {
                return $plain;
            }
        }
        return $valid($raw) ? $raw : '';
    }

    public static function telegramToken(): string
    {
        return self::secret('telegram_bot_token', static fn ($v) => (bool) preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $v));
    }

    public static function slackWebhook(): string
    {
        return self::secret('slack_webhook_url', static fn ($v) => strpos($v, 'https://hooks.slack.com/') === 0 && filter_var($v, FILTER_VALIDATE_URL) !== false);
    }
}
