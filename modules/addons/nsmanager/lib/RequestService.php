<?php

namespace NsManager;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Single entry point for every nameserver-request state change, so the
 * registrar module, admin UI and cron never write rows in different ways.
 *
 * Actors are plain strings: "client:<id>", "admin:<id>" or "system".
 * Every public method returns an array with at least 'success' (bool) and,
 * on failure, 'error' (string). Notifications are added in Phase 4.
 */
class RequestService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPLIED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_SUPERSEDED,
    ];

    /**
     * Create a pending request for a domain.
     *
     * Result keys: success, error, request_id, noop (bool), superseded_id (?int).
     *
     * @param array<int|string, mixed> $newNs
     * @return array<string, mixed>
     */
    public static function createRequest(int $domainId, array $newNs, string $actor, ?string $ip = null): array
    {
        $check = Validator::validate($newNs);
        if (!$check['ok']) {
            return self::error($check['error']);
        }
        $new = $check['ns'];

        $domain = Repository::getWhmcsDomain($domainId);
        if (!$domain) {
            return self::error('Domain not found.');
        }

        return Capsule::connection()->transaction(function () use ($domain, $domainId, $new, $actor, $ip) {
            $applied = Repository::getAppliedNs($domainId);
            $pending = Repository::findPendingByDomain($domainId);

            // Same as the open request: nothing to do.
            if ($pending && Repository::decodeNs($pending->new_ns) === $new) {
                return ['success' => true, 'noop' => true, 'request_id' => (int) $pending->id, 'superseded_id' => null];
            }

            // Back to what is already live at the provider: withdraw the open request.
            if ($applied === $new) {
                if (!$pending) {
                    return ['success' => true, 'noop' => true, 'request_id' => null, 'superseded_id' => null];
                }
                self::closePending($pending, self::STATUS_SUPERSEDED, $actor, 'Reverted to the nameservers already live.');
                return ['success' => true, 'noop' => true, 'request_id' => null, 'superseded_id' => (int) $pending->id];
            }

            $supersededId = null;
            if ($pending) {
                self::closePending($pending, self::STATUS_SUPERSEDED, $actor, 'Replaced by a newer request.');
                $supersededId = (int) $pending->id;
            }

            $requestId = Repository::insertRequest([
                'domain_id' => $domainId,
                'domain' => $domain->domain,
                'client_id' => (int) $domain->userid,
                'old_ns' => Repository::encodeNs($applied),
                'new_ns' => Repository::encodeNs($new),
                'status' => self::STATUS_PENDING,
                'requested_by' => $actor,
                'requester_ip' => $ip,
            ]);
            Repository::addLog($domainId, $requestId, 'created', $actor, [
                'old_ns' => $applied,
                'new_ns' => $new,
                'superseded_id' => $supersededId,
            ]);

            return ['success' => true, 'noop' => false, 'request_id' => $requestId, 'superseded_id' => $supersededId];
        });
    }

    /**
     * The nameservers are now live at the provider.
     *
     * @return array<string, mixed>
     */
    public static function markApplied(int $requestId, string $actor, ?string $note = null): array
    {
        return Capsule::connection()->transaction(function () use ($requestId, $actor, $note) {
            $request = self::loadPending($requestId);
            if (is_array($request)) {
                return $request;
            }

            self::resolve($request, self::STATUS_APPLIED, $actor, $note);
            Repository::setAppliedNs((int) $request->domain_id, Repository::decodeNs($request->new_ns));
            Repository::addLog((int) $request->domain_id, $requestId, 'applied', $actor, $note);

            return ['success' => true, 'request_id' => $requestId];
        });
    }

    /**
     * The change could not be made. A reason is required.
     *
     * @return array<string, mixed>
     */
    public static function markFailed(int $requestId, string $actor, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return self::error('A reason is required when marking a request as failed.');
        }

        return Capsule::connection()->transaction(function () use ($requestId, $actor, $reason) {
            $request = self::loadPending($requestId);
            if (is_array($request)) {
                return $request;
            }

            self::resolve($request, self::STATUS_FAILED, $actor, $reason);
            Repository::addLog((int) $request->domain_id, $requestId, 'failed', $actor, $reason);

            return ['success' => true, 'request_id' => $requestId];
        });
    }

    /**
     * Withdraw a pending request (customer or admin).
     *
     * @return array<string, mixed>
     */
    public static function cancel(int $requestId, string $actor, ?string $note = null): array
    {
        return Capsule::connection()->transaction(function () use ($requestId, $actor, $note) {
            $request = self::loadPending($requestId);
            if (is_array($request)) {
                return $request;
            }

            self::resolve($request, self::STATUS_CANCELLED, $actor, $note);
            Repository::addLog((int) $request->domain_id, $requestId, 'cancelled', $actor, $note);

            return ['success' => true, 'request_id' => $requestId];
        });
    }

    /**
     * Nameservers to show the customer: the open request if any, else the live ones.
     *
     * @return string[]
     */
    public static function currentNameservers(int $domainId): array
    {
        $pending = Repository::findPendingByDomain($domainId);
        if ($pending) {
            return Repository::decodeNs($pending->new_ns);
        }
        return Repository::getAppliedNs($domainId);
    }

    // ---- internals -----------------------------------------------------

    /**
     * @return object|array<string, mixed> The pending request row, or an error result.
     */
    private static function loadPending(int $requestId)
    {
        $request = Repository::findRequest($requestId);
        if (!$request) {
            return self::error('Request not found.');
        }
        if ($request->status !== self::STATUS_PENDING) {
            return self::error('Request is already ' . $request->status . '.');
        }
        return $request;
    }

    private static function resolve(object $request, string $status, string $actor, ?string $note): void
    {
        Repository::updateRequest((int) $request->id, [
            'status' => $status,
            'resolved_by' => $actor,
            'resolved_at' => Repository::now(),
            'admin_note' => $note,
        ]);
    }

    private static function closePending(object $pending, string $status, string $actor, string $note): void
    {
        self::resolve($pending, $status, $actor, $note);
        Repository::addLog((int) $pending->domain_id, (int) $pending->id, $status, $actor, $note);
    }

    /**
     * @return array{success: false, error: string}
     */
    private static function error(string $message): array
    {
        return ['success' => false, 'error' => $message];
    }
}
