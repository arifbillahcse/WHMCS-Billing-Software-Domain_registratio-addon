<?php

namespace NsManager;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Sends admin alerts (email, ticket, Telegram, Slack) and customer emails.
 *
 * Nothing here ever throws: a failing channel is written to the audit log as
 * 'notify_failed' and the request flow carries on. Admin alerts are skipped
 * for requests an admin created themselves.
 */
class Notifier
{
    /** @var callable|null fn(string $url, array $payload): void, throws on failure */
    private static $httpOverride = null;

    /** @var callable|null fn(string $command, array $values): array */
    private static $apiOverride = null;

    public static function setHttpClient(?callable $client): void
    {
        self::$httpOverride = $client;
    }

    public static function setApiClient(?callable $client): void
    {
        self::$apiOverride = $client;
    }

    // ---- public entry points -----------------------------------------

    /**
     * A new request was queued: alert the admins.
     */
    public static function requestCreated(int $requestId, ?int $supersededId = null): void
    {
        try {
            $request = Repository::findRequest($requestId);
            if (!$request || strpos((string) $request->requested_by, 'admin:') === 0) {
                return;
            }
            $subject = '[NS change] ' . $request->domain . ' - request #' . $requestId;
            self::sendToAdmins($subject, self::adminText($request, $supersededId), $request);
        } catch (\Throwable $e) {
            self::logFailure(null, $requestId, 'notifier', $e->getMessage());
        }
    }

    /**
     * Daily digest of requests that have been pending too long.
     *
     * @param object[] $requests pending request rows, oldest first
     * @return int number of channels that delivered it (0 = nothing was sent)
     */
    public static function sendReminderDigest(array $requests, int $hours): int
    {
        if (!$requests) {
            return 0;
        }
        try {
            $lines = [count($requests) . ' nameserver change request(s) pending for more than ' . $hours . 'h:'];
            foreach (array_slice($requests, 0, 25) as $r) {
                $meta = Repository::getDomainMeta((int) $r->domain_id);
                $lines[] = '#' . (int) $r->id . ' ' . $r->domain . ' - waiting ' . View::ageText($r->created_at)
                    . ($meta && $meta->provider ? ' - ' . $meta->provider : '');
            }
            if (count($requests) > 25) {
                $lines[] = '...and ' . (count($requests) - 25) . ' more.';
            }
            $link = self::adminUrl();
            if ($link !== '') {
                $lines[] = 'Open the queue: ' . $link;
            }
            return self::sendToAdmins('[NS change] ' . count($requests) . ' request(s) still pending', self::clean(implode("\n", $lines), 4000), null);
        } catch (\Throwable $e) {
            self::logFailure(null, null, 'reminder', $e->getMessage());
            return 0;
        }
    }

    /**
     * A request was applied or failed: tell the customer (if enabled).
     */
    public static function requestResolved(int $requestId): void
    {
        try {
            $request = Repository::findRequest($requestId);
            if (!$request) {
                return;
            }
            $status = (string) $request->status;
            if ($status === RequestService::STATUS_APPLIED && Settings::bool('notify_customer_applied')) {
                self::emailCustomer($request, 'Nameserver update completed for ' . $request->domain, self::appliedBody($request));
            } elseif ($status === RequestService::STATUS_FAILED && Settings::bool('notify_customer_failed')) {
                self::emailCustomer($request, 'Nameserver update could not be completed for ' . $request->domain, self::failedBody($request));
            }
        } catch (\Throwable $e) {
            self::logFailure(null, $requestId, 'customer_email', $e->getMessage());
        }
    }

    /**
     * Admin test button: push a test message through every enabled channel.
     *
     * @return array<string, array{ok: bool, message: string}>
     */
    public static function sendTest(): array
    {
        $text = "Nameserver Manager test notification.\nIf you can read this, this channel works.";
        $results = [];
        foreach (self::channels() as $name => $channel) {
            if (!$channel['enabled']) {
                $results[$name] = ['ok' => false, 'message' => 'Disabled in addon settings.'];
                continue;
            }
            try {
                $channel['send']('[NS change] Test notification', $text, null);
                $results[$name] = ['ok' => true, 'message' => 'Sent.'];
            } catch (\Throwable $e) {
                $results[$name] = ['ok' => false, 'message' => self::scrub($e->getMessage())];
            }
        }
        return $results;
    }

    /**
     * Channel status for the settings tab.
     *
     * @return array<string, array{enabled: bool, configured: bool}>
     */
    public static function status(): array
    {
        $out = [];
        foreach (self::channels() as $name => $channel) {
            $out[$name] = ['enabled' => $channel['enabled'], 'configured' => $channel['configured']];
        }
        return $out;
    }

    // ---- channels ------------------------------------------------------

    /**
     * @return array<string, array{enabled: bool, configured: bool, send: callable}>
     */
    private static function channels(): array
    {
        $deptId = (int) Settings::get('ticket_dept_id');
        $fromEmail = self::systemEmail();

        return [
            'Admin email' => [
                'enabled' => Settings::bool('notify_admin_email'),
                'configured' => true,
                'send' => static function (string $subject, string $text, $request) {
                    self::api('SendAdminEmail', [
                        'type' => 'system',
                        'customsubject' => self::clean($subject, 200),
                        'custommessage' => nl2br(View::e(self::clean($text, 4000))),
                    ]);
                },
            ],
            'Support ticket' => [
                'enabled' => Settings::bool('notify_ticket'),
                'configured' => $deptId > 0 && $fromEmail !== '',
                'send' => static function (string $subject, string $text, $request) use ($deptId, $fromEmail) {
                    if ($deptId <= 0 || $fromEmail === '') {
                        throw new \RuntimeException('Ticket department or system email is not configured.');
                    }
                    $priority = in_array(Settings::get('ticket_priority'), ['Low', 'Medium', 'High'], true) ? Settings::get('ticket_priority') : 'Medium';
                    self::api('OpenTicket', [
                        'deptid' => $deptId,
                        'subject' => self::clean($subject, 200),
                        'message' => self::clean($text, 4000),
                        'name' => 'Nameserver Manager',
                        'email' => $fromEmail,
                        'priority' => $priority,
                        'noemail' => true,
                    ]);
                },
            ],
            'Telegram' => [
                'enabled' => Settings::bool('notify_telegram'),
                'configured' => Settings::telegramToken() !== '' && Settings::get('telegram_chat_id') !== '',
                'send' => static function (string $subject, string $text, $request) {
                    $token = Settings::telegramToken();
                    $chat = Settings::get('telegram_chat_id');
                    if ($token === '' || $chat === '') {
                        throw new \RuntimeException('Telegram bot token or chat ID is not configured.');
                    }
                    self::postJson('https://api.telegram.org/bot' . $token . '/sendMessage', [
                        'chat_id' => $chat,
                        'text' => mb_substr($subject . "\n\n" . $text, 0, 4000),
                        'disable_web_page_preview' => true,
                    ], [$token]);
                },
            ],
            'Slack' => [
                'enabled' => Settings::bool('notify_slack'),
                'configured' => Settings::slackWebhook() !== '',
                'send' => static function (string $subject, string $text, $request) {
                    $hook = Settings::slackWebhook();
                    if ($hook === '') {
                        throw new \RuntimeException('Slack webhook URL is not configured.');
                    }
                    self::postJson($hook, ['text' => '*' . $subject . "*\n" . $text], [$hook]);
                },
            ],
        ];
    }

    /**
     * @return int number of channels that delivered the message
     */
    private static function sendToAdmins(string $subject, string $text, ?object $request): int
    {
        $sent = 0;
        foreach (self::channels() as $name => $channel) {
            if (!$channel['enabled']) {
                continue;
            }
            try {
                $channel['send']($subject, $text, $request);
                $sent++;
            } catch (\Throwable $e) {
                self::logFailure($request ? (int) $request->domain_id : null, $request ? (int) $request->id : null, $name, $e->getMessage());
            }
        }
        return $sent;
    }

    private static function emailCustomer(object $request, string $subject, string $body): void
    {
        try {
            self::api('SendEmail', [
                'customtype' => 'general',
                'customsubject' => self::clean($subject, 200),
                'custommessage' => $body,
                'id' => (int) $request->client_id,
            ]);
        } catch (\Throwable $e) {
            self::logFailure((int) $request->domain_id, (int) $request->id, 'Customer email', $e->getMessage());
        }
    }

    // ---- message builders ---------------------------------------------

    private static function adminText(object $request, ?int $supersededId): string
    {
        $meta = Repository::getDomainMeta((int) $request->domain_id);
        $provider = $meta && $meta->provider ? $meta->provider : 'not set';
        $login = $meta ? View::safeUrl($meta->provider_login_url) : '';
        if ($login !== '') {
            $provider .= ' (' . $login . ')';
        }
        if ($meta && $meta->account_label) {
            $provider .= ' - account: ' . $meta->account_label;
        }

        $old = Repository::decodeNs($request->old_ns);
        $lines = [
            'Domain: ' . $request->domain,
            'Customer: ' . Repository::getClientName((int) $request->client_id) . ' (#' . (int) $request->client_id . ')',
            'Provider: ' . $provider,
            'Requested: ' . $request->created_at . ' by ' . $request->requested_by . ($request->requester_ip ? ' from ' . $request->requester_ip : ''),
            'Old nameservers: ' . ($old ? implode(', ', $old) : 'unknown'),
            'New nameservers: ' . implode(', ', Repository::decodeNs($request->new_ns)),
        ];
        $glue = Validator::glueHosts((string) $request->domain, Repository::decodeNs($request->new_ns));
        if ($glue) {
            $lines[] = 'WARNING: ' . implode(', ', $glue) . ' sit under the domain itself - create the child nameserver (glue) records at the provider first.';
        }
        if ($supersededId) {
            $lines[] = 'Replaces earlier pending request #' . $supersededId . '.';
        }
        $link = self::adminUrl();
        if ($link !== '') {
            $lines[] = 'Review and apply: ' . $link;
        }
        return self::clean(implode("\n", $lines), 4000);
    }

    private static function appliedBody(object $request): string
    {
        $items = '';
        foreach (Repository::decodeNs($request->new_ns) as $ns) {
            $items .= '<li>' . View::e(self::clean($ns, 255)) . '</li>';
        }
        return '<p>Hello {$client_name},</p>'
            . '<p>The nameserver update for <strong>' . View::e(self::clean((string) $request->domain, 255)) . '</strong> has been completed. The domain now uses:</p>'
            . '<ul>' . $items . '</ul>'
            . '<p>DNS changes can take up to 24-48 hours to propagate worldwide.</p>';
    }

    private static function failedBody(object $request): string
    {
        $reason = trim((string) $request->admin_note);
        return '<p>Hello {$client_name},</p>'
            . '<p>We could not complete the nameserver update for <strong>' . View::e(self::clean((string) $request->domain, 255)) . '</strong>.</p>'
            . ($reason !== '' ? '<p>Reason: ' . View::e(self::clean($reason, 1000)) . '</p>' : '')
            . '<p>Your domain is still using its previous nameservers. Please contact our support team or submit a new update.</p>';
    }

    // ---- plumbing ------------------------------------------------------

    /**
     * Strip what must never reach a Smarty-rendered message ({ }) and control characters.
     */
    public static function clean(string $text, int $max = 1000): string
    {
        $text = str_replace(['{', '}', "\r"], '', $text);
        $text = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? '';
        return mb_substr($text, 0, $max);
    }

    public static function adminUrl(): string
    {
        $base = self::configValue('SystemURL');
        if ($base === '' || View::safeUrl($base) === '') {
            return '';
        }
        $adminDir = $GLOBALS['customadminpath'] ?? 'admin';
        $adminDir = preg_match('/^[A-Za-z0-9_-]+$/', (string) $adminDir) ? $adminDir : 'admin';
        return rtrim($base, '/') . '/' . $adminDir . '/addonmodules.php?module=nsmanager';
    }

    private static function systemEmail(): string
    {
        $email = self::configValue('SystemEmailsFromEmail');
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private static function configValue(string $setting): string
    {
        try {
            return trim((string) Capsule::table('tblconfiguration')->where('setting', $setting)->value('value'));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function api(string $command, array $values): array
    {
        if (self::$apiOverride) {
            $result = (self::$apiOverride)($command, $values);
        } elseif (function_exists('localAPI')) {
            $result = localAPI($command, $values);
        } else {
            throw new \RuntimeException('WHMCS local API is not available.');
        }
        if (!is_array($result) || ($result['result'] ?? '') !== 'success') {
            throw new \RuntimeException($command . ' failed: ' . (is_array($result) ? ($result['message'] ?? 'unknown error') : 'no response'));
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $secrets values to hide if they appear in an error message
     */
    private static function postJson(string $url, array $payload, array $secrets = []): void
    {
        if (strpos($url, 'https://') !== 0) {
            throw new \RuntimeException('Only https URLs are allowed.');
        }
        try {
            if (self::$httpOverride) {
                (self::$httpOverride)($url, $payload);
                return;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                throw new \RuntimeException('Request failed: ' . $error);
            }
            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException('HTTP ' . $code);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException(str_replace($secrets, '***', $e->getMessage()));
        }
    }

    private static function scrub(string $message): string
    {
        return mb_substr($message, 0, 300);
    }

    private static function logFailure(?int $domainId, ?int $requestId, string $channel, string $error): void
    {
        try {
            Repository::addLog($domainId, $requestId, 'notify_failed', 'system', $channel . ': ' . self::scrub($error));
            if (function_exists('logActivity')) {
                logActivity('Nameserver Manager: ' . $channel . ' notification failed - ' . self::scrub($error));
            }
        } catch (\Throwable $e) {
            // Logging must never break the caller.
        }
    }
}
