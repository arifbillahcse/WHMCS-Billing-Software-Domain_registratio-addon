<?php

namespace NsManager;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Validator
{
    public const MIN_NS = 2;
    public const MAX_NS = 5;

    /**
     * Trim, lowercase, strip trailing dots, drop empties and reindex.
     *
     * @param array<int|string, mixed> $ns
     * @return string[]
     */
    public static function normalize(array $ns): array
    {
        $out = [];
        foreach ($ns as $value) {
            $value = strtolower(rtrim(trim((string) $value), '.'));
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * @param array<int|string, mixed> $ns
     * @return array{ok: bool, ns: string[], error: string}
     */
    public static function validate(array $ns): array
    {
        $ns = self::normalize($ns);

        if (count($ns) < self::MIN_NS) {
            return self::fail($ns, 'At least ' . self::MIN_NS . ' nameservers are required.');
        }
        if (count($ns) > self::MAX_NS) {
            return self::fail($ns, 'No more than ' . self::MAX_NS . ' nameservers are allowed.');
        }
        if (count(array_unique($ns)) !== count($ns)) {
            return self::fail($ns, 'Duplicate nameservers are not allowed.');
        }
        foreach ($ns as $host) {
            if (!self::isHostname($host)) {
                return self::fail($ns, 'Invalid nameserver hostname: ' . $host);
            }
        }

        return ['ok' => true, 'ns' => $ns, 'error' => ''];
    }

    public static function isHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        // Nameservers must be hostnames, not IP addresses.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }
        // A purely numeric TLD is never valid.
        return !ctype_digit(end($labels));
    }

    /**
     * Nameservers that live under the domain itself (ns1.example.com for
     * example.com). The registrar needs a glue record for these.
     *
     * @param string[] $ns
     * @return string[]
     */
    public static function glueHosts(string $domain, array $ns): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        return array_values(array_filter($ns, static function ($host) use ($domain) {
            $host = strtolower($host);
            return $host === $domain || substr($host, -strlen($domain) - 1) === '.' . $domain;
        }));
    }

    /**
     * @param string[] $ns
     * @return array{ok: bool, ns: string[], error: string}
     */
    private static function fail(array $ns, string $error): array
    {
        return ['ok' => false, 'ns' => $ns, 'error' => $error];
    }
}
