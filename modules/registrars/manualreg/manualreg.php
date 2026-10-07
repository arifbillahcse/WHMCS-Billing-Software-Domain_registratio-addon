<?php
/**
 * Manual Registrar - for domains bought at external providers.
 *
 * No provider API is called. Saving nameservers records a pending request in
 * the Nameserver Manager addon (modules/addons/nsmanager); an admin applies it
 * at the provider and marks it applied. Requires that addon to be activated.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

function manualreg_MetaData()
{
    return [
        'DisplayName' => 'Manual Registrar',
        'APIVersion' => '1.1',
    ];
}

function manualreg_getConfigArray()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Manual Registrar',
        ],
        'Description' => [
            'Type' => 'System',
            'Value' => 'Domains held at external providers. Nameserver changes are queued for an admin to apply manually. Requires the Nameserver Manager addon.',
        ],
    ];
}

/**
 * Nameservers shown to the customer: the open request if there is one, else
 * the ones last marked as applied at the provider.
 */
function manualreg_GetNameservers($params)
{
    try {
        manualreg_load();
        $stored = NsManager\RequestService::currentNameservers((int) $params['domainid']);
    } catch (\Throwable $e) {
        return manualreg_fail('GetNameservers', $params, $e);
    }

    $values = [];
    for ($i = 1; $i <= NsManager\Validator::MAX_NS; $i++) {
        $values['ns' . $i] = $stored[$i - 1] ?? '';
    }
    return $values;
}

/**
 * Records the change as a pending request; nothing is sent to a provider.
 */
function manualreg_SaveNameservers($params)
{
    $domainId = (int) $params['domainid'];

    try {
        manualreg_load();

        [$actor, $isAdmin] = manualreg_actor();
        $domain = NsManager\Repository::getWhmcsDomain($domainId);
        if (!$domain) {
            return ['error' => 'Domain not found.'];
        }
        // WHMCS checks ownership before calling the module; this is a second line of defence.
        if (!$isAdmin && !empty($_SESSION['uid']) && (int) $domain->userid !== (int) $_SESSION['uid']) {
            return ['error' => 'You do not have access to this domain.'];
        }

        $submitted = [];
        for ($i = 1; $i <= NsManager\Validator::MAX_NS; $i++) {
            $submitted[] = $params['ns' . $i] ?? '';
        }

        $result = NsManager\RequestService::createRequest($domainId, $submitted, $actor, manualreg_ip());
    } catch (\Throwable $e) {
        return manualreg_fail('SaveNameservers', $params, $e);
    }

    if (!$result['success']) {
        return ['error' => $result['error']];
    }
    return ['success' => true];
}

// Nothing to register, transfer or renew with a provider: report success so
// WHMCS can complete the order/renewal and the admin handles it by hand.

function manualreg_RegisterDomain($params)
{
    return ['success' => true];
}

function manualreg_TransferDomain($params)
{
    return ['success' => true];
}

function manualreg_RenewDomain($params)
{
    return ['success' => true];
}

// ---- helpers -----------------------------------------------------------

/**
 * Load the shared classes from the addon.
 *
 * @throws \RuntimeException when the addon is missing or its tables were not created
 */
function manualreg_load()
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $libDir = (defined('ROOTDIR') ? ROOTDIR : dirname(__DIR__, 3)) . '/modules/addons/nsmanager/lib';
    foreach (['Schema', 'Validator', 'View', 'Settings', 'Repository', 'Notifier', 'RequestService'] as $class) {
        $file = $libDir . '/' . $class . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Nameserver Manager addon files not found.');
        }
        require_once $file;
    }
    if (!Capsule::schema()->hasTable(NsManager\Schema::REQUESTS)) {
        throw new \RuntimeException('Nameserver Manager addon is not activated.');
    }
    $loaded = true;
}

/**
 * Who is making the change: "admin:<id>" in the admin area, else "client:<id>".
 *
 * @return array{0: string, 1: bool} actor, isAdmin
 */
function manualreg_actor()
{
    if (!empty($_SESSION['adminid'])) {
        return ['admin:' . (int) $_SESSION['adminid'], true];
    }
    if (!empty($_SESSION['uid'])) {
        return ['client:' . (int) $_SESSION['uid'], false];
    }
    return ['system', false];
}

function manualreg_ip()
{
    if (class_exists('WHMCS\Utility\Environment\CurrentRequest')) {
        return WHMCS\Utility\Environment\CurrentRequest::getIP() ?: null;
    }
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

/**
 * Log the real error for admins; show the customer a generic one.
 */
function manualreg_fail($action, $params, \Throwable $e)
{
    if (function_exists('logModuleCall')) {
        logModuleCall('manualreg', $action, ['domainid' => $params['domainid'] ?? null], $e->getMessage(), $e->getTraceAsString());
    }
    return ['error' => 'Nameserver changes are temporarily unavailable. Please contact support.'];
}
