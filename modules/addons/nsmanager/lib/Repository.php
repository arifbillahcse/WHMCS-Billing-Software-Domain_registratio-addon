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
