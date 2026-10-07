<?php

namespace NsManager;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Admin dashboard: reads the request, runs any POST action through
 * RequestService, and returns the page HTML. Access control (which staff roles
 * may open the addon) is handled by WHMCS Addon Module permissions.
 */
class AdminController
{
    public const PER_PAGE = 25;
    private const TABS = ['pending', 'applied', 'failed', 'all', 'providers', 'settings'];

    /**
     * @param array<string, mixed> $vars   WHMCS addon vars (modulelink, ...)
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     */
    public static function handle(array $vars, array $get, array $post, string $actor, bool $isPost): string
    {
        $base = (string) ($vars['modulelink'] ?? 'addonmodules.php?module=nsmanager');
        $flash = $isPost ? self::handlePost($post, $actor) : [];
        $testResults = isset($post['test_notify']) ? Notifier::sendTest() : null;

        $tab = in_array($get['tab'] ?? '', self::TABS, true) ? $get['tab'] : 'pending';
        $page = max(1, (int) ($get['page'] ?? 1));
        $url = static fn (array $params = []): string => $base . ($params ? '&' . http_build_query($params) : '');

        $common = ['url' => $url, 'token' => self::token(), 'perPage' => self::PER_PAGE];

        if (($get['action'] ?? '') === 'domain') {
            $body = self::domainPage((int) ($get['id'] ?? 0), $common);
            $tab = '';
        } elseif ($tab === 'settings') {
            $body = View::render('settings', $common + ['status' => Notifier::status(), 'results' => $testResults]);
        } elseif ($tab === 'providers') {
            $body = self::providersPage((string) ($get['q'] ?? ''), $page, $common);
        } else {
            $body = self::queuePage($tab, $page, $common);
        }

        return View::render('layout', [
            'url' => $url,
            'tab' => $tab,
            'counts' => Repository::countByStatus(),
            'flash' => $flash,
            'body' => $body,
        ]);
    }

    // ---- pages ---------------------------------------------------------

    /**
     * @param array<string, mixed> $common
     */
    private static function queuePage(string $tab, int $page, array $common): string
    {
        $status = $tab === 'all' ? null : $tab;
        $total = Repository::countRequests($status);
        $rows = Repository::listRequestsDetailed($status, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'row' => $row,
                'client' => Repository::formatClientName($row),
                'old' => Repository::decodeNs($row->old_ns),
                'new' => Repository::decodeNs($row->new_ns),
                'link' => View::safeUrl($row->provider_login_url),
            ];
        }

        return View::render('queue', $common + [
            'tab' => $tab,
            'items' => $items,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    /**
     * @param array<string, mixed> $common
     */
    private static function providersPage(string $search, int $page, array $common): string
    {
        $search = trim($search);
        $total = Repository::countManualDomains($search);
        $rows = Repository::listManualDomains($search, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'row' => $row,
                'client' => Repository::formatClientName($row),
                'applied' => Repository::decodeNs($row->applied_ns),
                'link' => View::safeUrl($row->provider_login_url),
            ];
        }

        return View::render('providers', $common + [
            'items' => $items,
            'search' => $search,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    /**
     * @param array<string, mixed> $common
     */
    private static function domainPage(int $domainId, array $common): string
    {
        $domain = Repository::getWhmcsDomain($domainId);
        if (!$domain) {
            return View::render('notfound', $common);
        }

        $meta = Repository::getDomainMeta($domainId);
        return View::render('domain', $common + [
            'domain' => $domain,
            'client' => Repository::getClientName((int) $domain->userid),
            'meta' => $meta,
            'applied' => $meta ? Repository::decodeNs($meta->applied_ns) : [],
            'link' => $meta ? View::safeUrl($meta->provider_login_url) : '',
            'requests' => Repository::getRequestsByDomain($domainId),
            'log' => Repository::getLog($domainId, null, 100),
        ]);
    }

    // ---- actions -------------------------------------------------------

    /**
     * @param array<string, mixed> $post
     * @return array<int, array{type: string, text: string}>
     */
    private static function handlePost(array $post, string $actor): array
    {
        if (!empty($post['row_action']) && preg_match('/^(apply|fail|cancel):(\d+)$/', (string) $post['row_action'], $m)) {
            return [self::singleAction($m[1], (int) $m[2], $actor, (string) ($post['reason'] ?? ''))];
        }
        if (($post['bulk'] ?? '') === 'apply') {
            return self::bulkApply((array) ($post['ids'] ?? []), $actor);
        }
        if (isset($post['save_domain'])) {
            return [self::saveDomain($post, $actor)];
        }
        return [];
    }

    /**
     * @return array{type: string, text: string}
     */
    private static function singleAction(string $action, int $id, string $actor, string $reason): array
    {
        if ($action === 'apply') {
            $result = RequestService::markApplied($id, $actor, trim($reason) ?: null);
            $done = 'Request #' . $id . ' marked as applied.';
        } elseif ($action === 'fail') {
            $result = RequestService::markFailed($id, $actor, $reason);
            $done = 'Request #' . $id . ' marked as failed.';
        } else {
            $result = RequestService::cancel($id, $actor, trim($reason) ?: null);
            $done = 'Request #' . $id . ' cancelled.';
        }

        return $result['success']
            ? ['type' => 'success', 'text' => $done]
            : ['type' => 'danger', 'text' => 'Request #' . $id . ': ' . $result['error']];
    }

    /**
     * @param array<int|string, mixed> $ids
     * @return array<int, array{type: string, text: string}>
     */
    private static function bulkApply(array $ids, string $actor): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [['type' => 'warning', 'text' => 'No requests selected.']];
        }

        $ok = 0;
        $errors = [];
        foreach ($ids as $id) {
            $result = RequestService::markApplied($id, $actor);
            if ($result['success']) {
                $ok++;
            } else {
                $errors[] = 'Request #' . $id . ': ' . $result['error'];
            }
        }

        $flash = [];
        if ($ok) {
            $flash[] = ['type' => 'success', 'text' => $ok . ' request(s) marked as applied.'];
        }
        foreach ($errors as $error) {
            $flash[] = ['type' => 'danger', 'text' => $error];
        }
        return $flash;
    }

    /**
     * Save provider details and the nameservers currently live at the provider.
     *
     * @param array<string, mixed> $post
     * @return array{type: string, text: string}
     */
    private static function saveDomain(array $post, string $actor): array
    {
        $domainId = (int) ($post['domain_id'] ?? 0);
        if (!Repository::getWhmcsDomain($domainId)) {
            return ['type' => 'danger', 'text' => 'Domain not found.'];
        }

        $loginUrl = trim((string) ($post['provider_login_url'] ?? ''));
        if ($loginUrl !== '' && View::safeUrl($loginUrl) === '') {
            return ['type' => 'danger', 'text' => 'Provider login URL must start with http:// or https://.'];
        }

        // Only touch the live nameservers when the field was submitted; an empty value clears them.
        $liveNs = null;
        if (array_key_exists('applied_ns', $post)) {
            $liveRaw = trim((string) $post['applied_ns']);
            $liveNs = [];
            if ($liveRaw !== '') {
                $live = Validator::validate(preg_split('/[\s,]+/', $liveRaw) ?: []);
                if (!$live['ok']) {
                    return ['type' => 'danger', 'text' => 'Live nameservers: ' . $live['error']];
                }
                $liveNs = $live['ns'];
            }
        }

        Repository::upsertDomainMeta($domainId, [
            'provider' => self::cut($post['provider'] ?? '', 100),
            'provider_login_url' => self::cut($loginUrl, 255),
            'account_label' => self::cut($post['account_label'] ?? '', 100),
            'notes' => self::cut($post['notes'] ?? '', 5000),
        ]);

        $before = Repository::getAppliedNs($domainId);
        if ($liveNs !== null && $before !== $liveNs) {
            Repository::setAppliedNs($domainId, $liveNs);
            Repository::addLog($domainId, null, 'applied_ns_set', $actor, ['old_ns' => $before, 'new_ns' => $liveNs]);
        }
        Repository::addLog($domainId, null, 'domain_updated', $actor, 'Provider details saved.');

        return ['type' => 'success', 'text' => 'Domain details saved.'];
    }

    // ---- helpers -------------------------------------------------------

    /**
     * @param mixed $value
     */
    private static function cut($value, int $max): string
    {
        $value = trim((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private static function token(): string
    {
        return function_exists('generate_token') ? (string) generate_token('plain') : '';
    }
}
