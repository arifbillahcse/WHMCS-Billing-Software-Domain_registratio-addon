<?php

namespace NsManager;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Looks up the nameservers a domain currently delegates to.
 */
class DnsVerifier
{
    /** @var callable|null fn(string $domain): ?array, for tests */
    private static $override = null;

    public static function setResolver(?callable $resolver): void
    {
        self::$override = $resolver;
    }

    /**
     * @return string[]|null sorted lowercase hostnames, or null when the lookup failed or returned nothing
     */
    public static function lookup(string $domain): ?array
    {
        if (self::$override) {
            return (self::$override)($domain);
        }
        if (!function_exists('dns_get_record')) {
            return null;
        }

        $records = @dns_get_record($domain, DNS_NS);
        if (!is_array($records) || !$records) {
            return null;
        }

        $hosts = [];
        foreach ($records as $record) {
            if (!empty($record['target'])) {
                $hosts[] = strtolower(rtrim((string) $record['target'], '.'));
            }
        }
        $hosts = array_values(array_unique($hosts));
        sort($hosts);
        return $hosts ?: null;
    }
}
