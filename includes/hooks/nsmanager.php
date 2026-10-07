<?php
/**
 * Entry point WHMCS auto-loads from includes/hooks/. The real hooks live in the addon.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$nsmanagerHooks = ROOTDIR . '/modules/addons/nsmanager/hooks.php';
if (is_file($nsmanagerHooks)) {
    require_once $nsmanagerHooks;
}
unset($nsmanagerHooks);
