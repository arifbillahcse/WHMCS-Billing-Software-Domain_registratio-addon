<?php

namespace NsManager;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Work done from WHMCS cron hooks. Never throws.
 */
class Cron
{
    public const VERIFY_INTERVAL_MINUTES = 15;
    public const MAX_CHECKS_PER_RUN = 50;

    /**
     * Cancel pending requests nobody can act on any more, so they stop appearing
     * in the queue and reminders.
     */
    public static function cancelOrphans(): int
    {
        $cancelled = 0;
        try {
            foreach (Repository::pendingOrphans() as $request) {
                $result = RequestService::cancel((int) $request->id, 'system', 'Domain was removed or no longer uses the Manual Registrar.');
                $cancelled += $result['success'] ? 1 : 0;
            }
        } catch (\Throwable $e) {
            self::log('orphan cleanup', $e);
        }
        return $cancelled;
    }

    /**
     * Send one digest for requests pending longer than the reminder window.
     *
     * @return array{overdue: int, sent: bool}
     */
    public static function sendReminders(): array
    {
        self::cancelOrphans();

        try {
            $hours = Settings::int('reminder_hours');
            $hours = $hours > 0 ? $hours : 24;
            $cutoff = date('Y-m-d H:i:s', time() - $hours * 3600);

            $overdue = Repository::pendingOlderThan($cutoff);
            if (!$overdue) {
                return ['overdue' => 0, 'sent' => false];
            }

            $sent = Notifier::sendReminderDigest($overdue, $hours) > 0;
            if ($sent) {
                Repository::markReminded(array_map(static fn ($r) => (int) $r->id, $overdue));
            }
            return ['overdue' => count($overdue), 'sent' => $sent];
        } catch (\Throwable $e) {
            self::log('reminders', $e);
            return ['overdue' => 0, 'sent' => false];
        }
    }

    /**
     * Close pending requests whose nameservers are now live. A mismatch just
     * means "keep waiting": propagation can take hours, so it is never "failed".
     *
     * @return array{checked: int, applied: int, waiting: int, errors: int}
     */
    public static function verifyPending(): array
    {
        $out = ['checked' => 0, 'applied' => 0, 'waiting' => 0, 'errors' => 0];
        try {
            if (!Settings::bool('auto_verify_dns')) {
                return $out;
            }

            $cutoff = date('Y-m-d H:i:s', time() - self::VERIFY_INTERVAL_MINUTES * 60);
            foreach (Repository::pendingNeedingCheck($cutoff, self::MAX_CHECKS_PER_RUN) as $request) {
                $out['checked']++;
                Repository::updateRequest((int) $request->id, ['last_checked_at' => Repository::now()]);

                $live = DnsVerifier::lookup((string) $request->domain);
                if ($live === null) {
                    $out['errors']++;
                    continue;
                }

                $wanted = Repository::decodeNs($request->new_ns);
                sort($wanted);
                if ($live !== $wanted) {
                    $out['waiting']++;
                    continue;
                }

                $result = RequestService::markApplied((int) $request->id, 'system', 'Verified automatically: live DNS matches the requested nameservers.');
                $out[$result['success'] ? 'applied' : 'errors']++;
            }
        } catch (\Throwable $e) {
            self::log('dns verify', $e);
        }
        return $out;
    }

    private static function log(string $what, \Throwable $e): void
    {
        if (function_exists('logActivity')) {
            logActivity('Nameserver Manager ' . $what . ' failed: ' . mb_substr($e->getMessage(), 0, 300));
        }
    }
}
