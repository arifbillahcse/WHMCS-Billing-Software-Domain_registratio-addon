<?php
/**
 * Nameserver Manager - admin addon module.
 *
 * Stores customer nameserver change requests for domains managed at external
 * providers and lets admins work through them. Registrar-side logic lives in
 * modules/registrars/manualreg and shares the classes in lib/.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Schema.php';
require_once __DIR__ . '/lib/Validator.php';
require_once __DIR__ . '/lib/Repository.php';
require_once __DIR__ . '/lib/RequestService.php';
require_once __DIR__ . '/lib/View.php';
require_once __DIR__ . '/lib/AdminController.php';

function nsmanager_config()
{
    return [
        'name' => 'Nameserver Manager',
        'description' => 'Queue and track customer nameserver changes for domains held at external providers.',
        'version' => '0.1.0',
        'author' => 'Hostorio',
        'language' => 'english',
        'fields' => [],
    ];
}

function nsmanager_activate()
{
    try {
        NsManager\Schema::install();
        return ['status' => 'success', 'description' => 'Nameserver Manager tables created.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Could not create tables: ' . $e->getMessage()];
    }
}

function nsmanager_deactivate()
{
    // Data is kept on purpose: requests and the audit log must survive a deactivate.
    return ['status' => 'success', 'description' => 'Deactivated. Data tables were kept.'];
}

function nsmanager_upgrade($vars)
{
    // Schema::install() only creates missing tables; add column migrations here per version.
    NsManager\Schema::install();
}

function nsmanager_output($vars)
{
    $actor = !empty($_SESSION['adminid']) ? 'admin:' . (int) $_SESSION['adminid'] : 'system';
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    try {
        echo NsManager\AdminController::handle($vars, $_GET, $_POST, $actor, $isPost);
    } catch (\Throwable $e) {
        logActivity('Nameserver Manager error: ' . $e->getMessage());
        echo '<div class="alert alert-danger">Nameserver Manager hit an error. Details were written to the Activity Log.</div>';
    }
}
