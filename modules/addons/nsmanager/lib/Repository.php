<?php

namespace NsManager;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * All database access for the addon. No business rules live here.
 */
class Repository
{
    // ---- helpers -------------------------------------------------------

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * @param string[] $ns
     */
    public static function encodeNs(array $ns): string
    {
        return json_encode(array_values($ns), JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return string[]
     */
    public static function decodeNs(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? array_values($data) : [];
    }

    // ---- WHMCS domains -------------------------------------------------

    public static function getWhmcsDomain(int $domainId): ?object
    {
        $row = Capsule::table('tbldomains')
            ->where('id', $domainId)
            ->first(['id', 'userid', 'domain']);
        return $row ?: null;
    }

    // ---- requests ------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public static function insertRequest(array $data): int
    {
        $now = self::now();
        return (int) Capsule::table(Schema::REQUESTS)->insertGetId($data + [
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function findRequest(int $id): ?object
    {
        $row = Capsule::table(Schema::REQUESTS)->where('id', $id)->first();
        return $row ?: null;
    }

    public static function findPendingByDomain(int $domainId): ?object
    {
        $row = Capsule::table(Schema::REQUESTS)
            ->where('domain_id', $domainId)
            ->where('status', RequestService::STATUS_PENDING)
            ->orderBy('id', 'desc')
            ->first();
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function updateRequest(int $id, array $data): void
    {
        Capsule::table(Schema::REQUESTS)
            ->where('id', $id)
            ->update($data + ['updated_at' => self::now()]);
    }

    /**
     * @return object[]
     */
    public static function listRequests(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $query = Capsule::table(Schema::REQUESTS);
        if ($status !== null) {
            $query->where('status', $status);
        }
        return $query->orderBy('id', 'desc')->offset($offset)->limit($limit)->get()->all();
    }

    /**
     * Requests joined with client name and provider info, newest first.
     *
     * @return object[]
     */
    public static function listRequestsDetailed(?string $status = null, int $limit = 25, int $offset = 0): array
    {
        $query = Capsule::table(Schema::REQUESTS . ' as r')
            ->leftJoin('tblclients as c', 'c.id', '=', 'r.client_id')
            ->leftJoin(Schema::DOMAINS . ' as d', 'd.domain_id', '=', 'r.domain_id')
            ->select('r.*', 'c.firstname', 'c.lastname', 'c.companyname', 'd.provider', 'd.provider_login_url', 'd.account_label');
        if ($status !== null) {
            $query->where('r.status', $status);
        }
        return $query->orderBy('r.id', 'desc')->offset($offset)->limit($limit)->get()->all();
    }

    public static function countRequests(?string $status = null): int
    {
        $query = Capsule::table(Schema::REQUESTS);
        if ($status !== null) {
            $query->where('status', $status);
        }
        return (int) $query->count();
    }

    /**
     * @return object[]
     */
    public static function getRequestsByDomain(int $domainId): array
    {
        return Capsule::table(Schema::REQUESTS)
            ->where('domain_id', $domainId)
            ->orderBy('id', 'desc')
            ->get()
            ->all();
    }

    /**
     * @return array<string, int> status => count
     */
    public static function countByStatus(): array
    {
        $rows = Capsule::table(Schema::REQUESTS)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $out[$row->status] = (int) $row->total;
        }
        return $out;
    }

    /**
     * Pending requests created before the cutoff and not reminded since it.
     *
     * @return object[]
     */
    public static function pendingOlderThan(string $cutoff): array
    {
        return Capsule::table(Schema::REQUESTS)
            ->where('status', RequestService::STATUS_PENDING)
            ->where('created_at', '<=', $cutoff)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->get()
            ->all();
    }

    // ---- per-domain metadata ------------------------------------------

    public static function getDomainMeta(int $domainId): ?object
    {
        $row = Capsule::table(Schema::DOMAINS)->where('domain_id', $domainId)->first();
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function upsertDomainMeta(int $domainId, array $data): void
    {
        $now = self::now();
        if (Capsule::table(Schema::DOMAINS)->where('domain_id', $domainId)->exists()) {
            Capsule::table(Schema::DOMAINS)
                ->where('domain_id', $domainId)
                ->update($data + ['updated_at' => $now]);
            return;
        }
        Capsule::table(Schema::DOMAINS)->insert($data + [
            'domain_id' => $domainId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return string[]
     */
    public static function getAppliedNs(int $domainId): array
    {
        $meta = self::getDomainMeta($domainId);
        return $meta ? self::decodeNs($meta->applied_ns) : [];
    }

    /**
     * @param string[] $ns
     */
    public static function setAppliedNs(int $domainId, array $ns): void
    {
        self::upsertDomainMeta($domainId, ['applied_ns' => self::encodeNs($ns)]);
    }

    // ---- admin listings -------------------------------------------------

    /**
     * Domains using the manualreg registrar, with client and provider info.
     *
     * @return object[]
     */
    public static function listManualDomains(?string $search = null, int $limit = 25, int $offset = 0): array
    {
        $pendingSql = '(SELECT COUNT(*) FROM ' . Schema::REQUESTS
            . " q WHERE q.domain_id = t.id AND q.status = 'pending') AS pending_count";

        return self::manualDomainsQuery($search)
            ->select('t.id as domain_id', 't.domain', 't.userid', 'c.firstname', 'c.lastname', 'c.companyname',
                'd.provider', 'd.provider_login_url', 'd.account_label', 'd.applied_ns')
            ->selectRaw($pendingSql)
            ->orderBy('t.domain')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->all();
    }

    public static function countManualDomains(?string $search = null): int
    {
        return (int) self::manualDomainsQuery($search)->count();
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private static function manualDomainsQuery(?string $search)
    {
        $query = Capsule::table('tbldomains as t')
            ->leftJoin('tblclients as c', 'c.id', '=', 't.userid')
            ->leftJoin(Schema::DOMAINS . ' as d', 'd.domain_id', '=', 't.id')
            ->where('t.registrar', 'manualreg');
        if ($search !== null && $search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('t.domain', 'like', $like)->orWhere('d.provider', 'like', $like);
            });
        }
        return $query;
    }

    public static function getClientName(int $clientId): string
    {
        $c = Capsule::table('tblclients')->where('id', $clientId)->first(['firstname', 'lastname', 'companyname']);
        return $c ? self::formatClientName($c) : 'Client #' . $clientId;
    }

    public static function formatClientName(object $row): string
    {
        $name = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
        $company = trim((string) ($row->companyname ?? ''));
        if ($name !== '' && $company !== '') {
            return $name . ' (' . $company . ')';
        }
        return $name !== '' ? $name : $company;
    }

    // ---- audit log -----------------------------------------------------

    /**
     * @param array<string, mixed>|string|null $details
     */
    public static function addLog(?int $domainId, ?int $requestId, string $event, string $actor, $details = null): void
    {
        if (is_array($details)) {
            $details = json_encode($details, JSON_UNESCAPED_SLASHES);
        }
        Capsule::table(Schema::LOG)->insert([
            'domain_id' => $domainId,
            'request_id' => $requestId,
            'event' => $event,
            'actor' => $actor,
            'details' => $details,
            'created_at' => self::now(),
        ]);
    }

    /**
     * @return object[]
     */
    public static function getLog(?int $domainId = null, ?int $requestId = null, int $limit = 100): array
    {
        $query = Capsule::table(Schema::LOG);
        if ($domainId !== null) {
            $query->where('domain_id', $domainId);
        }
        if ($requestId !== null) {
            $query->where('request_id', $requestId);
        }
        return $query->orderBy('id', 'desc')->limit($limit)->get()->all();
    }
}
