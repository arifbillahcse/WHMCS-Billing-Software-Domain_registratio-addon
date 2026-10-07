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
require_once __DIR__ . '/lib/View.php';
require_once __DIR__ . '/lib/Settings.php';
require_once __DIR__ . '/lib/Notifier.php';
require_once __DIR__ . '/lib/RequestService.php';
require_once __DIR__ . '/lib/AdminController.php';

function nsmanager_config()
{
    return [
        'name' => 'Nameserver Manager',
        'description' => 'Queue and track customer nameserver changes for domains held at external providers.',
        'version' => '0.1.0',
        'author' => 'Hostorio',
        'language' => 'english',
        'fields' => [
            'notify_admin_email' => [
                'FriendlyName' => 'Email admins',
                'Type' => 'yesno',
                'Description' => 'Email staff when a customer requests a nameserver change (uses each staff role\'s "system emails" setting).',
                'Default' => 'on',
            ],
            'notify_ticket' => [
                'FriendlyName' => 'Open a support ticket',
                'Type' => 'yesno',
                'Description' => 'Open an internal ticket for each new request (not linked to the customer).',
            ],
            'ticket_dept_id' => [
                'FriendlyName' => 'Ticket department ID',
                'Type' => 'text',
                'Size' => '6',
                'Description' => 'Numeric ID of the support department that receives the tickets.',
            ],
            'ticket_priority' => [
                'FriendlyName' => 'Ticket priority',
                'Type' => 'dropdown',
                'Options' => 'Low,Medium,High',
                'Default' => 'Medium',
            ],
            'notify_telegram' => [
                'FriendlyName' => 'Telegram alerts',
                'Type' => 'yesno',
                'Description' => 'Send new requests to a Telegram chat.',
            ],
            'telegram_bot_token' => [
                'FriendlyName' => 'Telegram bot token',
                'Type' => 'password',
                'Size' => '50',
                'Description' => 'From @BotFather, like 123456:ABC-DEF...',
            ],
            'telegram_chat_id' => [
                'FriendlyName' => 'Telegram chat ID',
                'Type' => 'text',
                'Size' => '20',
                'Description' => 'User, group or channel ID the bot may post to.',
            ],
            'notify_slack' => [
                'FriendlyName' => 'Slack alerts',
                'Type' => 'yesno',
                'Description' => 'Send new requests to a Slack channel.',
            ],
            'slack_webhook_url' => [
                'FriendlyName' => 'Slack webhook URL',
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'Incoming webhook, starts with https://hooks.slack.com/',
            ],
            'notify_customer_applied' => [
                'FriendlyName' => 'Email customer when applied',
                'Type' => 'yesno',
                'Default' => 'on',
            ],
            'notify_customer_failed' => [
                'FriendlyName' => 'Email customer when failed',
                'Type' => 'yesno',
                'Description' => 'The failure reason you enter is included in this email.',
                'Default' => 'on',
            ],
        ],
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
