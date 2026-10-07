<?php
/**
 * Nameserver Manager hooks. Loaded by includes/hooks/nsmanager.php.
 *
 * - AfterCronJob: auto-close pending requests once the live DNS matches.
 * - DailyCronJob: one digest for requests pending too long.
 * - ClientAreaFooterOutput: "Update pending" notice on the domain page.
 *
 * Classes are loaded lazily so these hooks stay cheap on every page load.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!function_exists('nsmanager_hooks_boot')) {
    /**
     * @return bool true when the addon is installed and its tables exist
     */
    function nsmanager_hooks_boot()
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        $dir = __DIR__ . '/lib/';
        foreach (['Schema', 'Validator', 'View', 'Settings', 'Repository', 'Notifier', 'RequestService', 'DnsVerifier', 'Cron', 'ClientBanner'] as $class) {
            if (!is_file($dir . $class . '.php')) {
                return $ready = false;
            }
            require_once $dir . $class . '.php';
        }

        try {
            $ready = WHMCS\Database\Capsule::schema()->hasTable(NsManager\Schema::REQUESTS)
                && WHMCS\Database\Capsule::schema()->hasColumn(NsManager\Schema::REQUESTS, 'last_checked_at');
        } catch (\Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (function_exists('add_hook')) {
    add_hook('AfterCronJob', 1, function () {
        if (nsmanager_hooks_boot()) {
            NsManager\Cron::verifyPending();
        }
    });

    add_hook('DailyCronJob', 1, function () {
        if (nsmanager_hooks_boot()) {
            NsManager\Cron::sendReminders();
        }
    });

    add_hook('ClientAreaFooterOutput', 1, function ($vars) {
        // Cheap checks first: this fires on every client-area page.
        if (($vars['templatefile'] ?? '') !== 'clientareadomaindetails' || !nsmanager_hooks_boot()) {
            return '';
        }
        try {
            return NsManager\ClientBanner::render(
                (string) $vars['templatefile'],
                (int) ($vars['domainid'] ?? $_GET['id'] ?? 0),
                (int) ($_SESSION['uid'] ?? 0)
            );
        } catch (\Throwable $e) {
            return '';
        }
    });
}
