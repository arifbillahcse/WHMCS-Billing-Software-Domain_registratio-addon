<?php

namespace NsManager;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * "Update pending" notice on the client-area domain page, injected as a small
 * script from the ClientAreaFooterOutput hook so no template edits are needed.
 */
class ClientBanner
{
    private const FAILED_VISIBLE_DAYS = 7;

    public static function render(string $templateFile, int $domainId, int $clientId): string
    {
        if ($templateFile !== 'clientareadomaindetails' || $domainId <= 0 || $clientId <= 0) {
            return '';
        }

        $domain = Repository::getWhmcsDomain($domainId);
        if (!$domain || (int) $domain->userid !== $clientId || $domain->registrar !== 'manualreg') {
            return '';
        }

        $html = self::message($domainId);
        if ($html === '') {
            return '';
        }

        $json = json_encode($html, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        return '<script>(function () {'
            . 'var html = ' . $json . ';'
            . 'function add() {'
            . 'var target = document.querySelector("#main-body .primary-content") || document.querySelector("#main-body") || document.querySelector("main") || document.body;'
            . 'var box = document.createElement("div"); box.innerHTML = html;'
            . 'target.insertBefore(box.firstChild, target.firstChild);'
            . '}'
            . 'if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", add); } else { add(); }'
            . '})();</script>';
    }

    /**
     * The notice's HTML (already escaped), or '' when there is nothing to say.
     */
    public static function message(int $domainId): string
    {
        $requests = Repository::getRequestsByDomain($domainId);
        if (!$requests) {
            return '';
        }

        $latest = $requests[0];
        if ($latest->status === RequestService::STATUS_PENDING) {
            return '<div class="alert alert-warning" id="nsmanager-banner" role="status">'
                . '<strong>Update pending.</strong> Your nameserver change to <code>'
                . View::e(implode(', ', Repository::decodeNs($latest->new_ns)))
                . '</code> was submitted on ' . View::e($latest->created_at)
                . ' and is being processed. It can take up to 48 hours to take effect.</div>';
        }

        if ($latest->status === RequestService::STATUS_FAILED
            && View::ageSeconds($latest->resolved_at) <= self::FAILED_VISIBLE_DAYS * 86400) {
            $reason = trim((string) $latest->admin_note);
            return '<div class="alert alert-danger" id="nsmanager-banner" role="alert">'
                . '<strong>Your last nameserver change could not be completed.</strong> '
                . ($reason !== '' ? View::e($reason) . ' ' : '')
                . 'Your domain is still using its previous nameservers. You can submit a new update or contact support.</div>';
        }

        return '';
    }
}
