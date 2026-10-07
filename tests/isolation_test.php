<?php
/**
 * Dev-only: loads ONLY the registrar module, like a real client-area request, so a
 * class missing from its loader cannot hide behind the addon being loaded too.
 * Also checks the real failure reason reaches the WHMCS Activity Log.
 *
 *   cd tests && composer install && php isolation_test.php
 */

require __DIR__ . '/bootstrap.php';
require ROOTDIR . '/modules/registrars/manualreg/manualreg.php';

use WHMCS\Database\Capsule;

Capsule::table('tbldomains')->insert(['id' => 1, 'userid' => 7, 'domain' => 'lehjabd.com', 'registrar' => 'manualreg', 'status' => 'Active']);
$_SESSION = ['uid' => 7];
$empty = ['domainid' => 1, 'ns1' => '', 'ns2' => '', 'ns3' => '', 'ns4' => '', 'ns5' => ''];

echo "addon tables missing (addon not activated)\n";
$get = manualreg_GetNameservers(['domainid' => 1]);
check('GetNameservers returns an error array', isset($get['error']));
check('Activity Log names the real cause', strpos(implode('|', $GLOBALS['__activity']), 'Manual Registrar GetNameservers failed for domain ID 1') !== false
    && strpos(implode('|', $GLOBALS['__activity']), 'not activated') !== false);
$save = manualreg_SaveNameservers($empty);
check('SaveNameservers returns an error array', isset($save['error']));
check('Activity Log line includes file and line', preg_match('/\(\w+\.php:\d+\)/', implode('|', $GLOBALS['__activity'])) === 1);

echo "after activation, with only the registrar's own class loader\n";
NsManager\Schema::install();
$get = manualreg_GetNameservers(['domainid' => 1]);
check('GetNameservers works', !isset($get['error']) && array_keys($get) === ['ns1', 'ns2', 'ns3', 'ns4', 'ns5']);
$save = manualreg_SaveNameservers($empty);
check('empty form gives a validation error, not a crash', isset($save['error']) && strpos($save['error'], 'At least 2') === 0);
$save = manualreg_SaveNameservers(['domainid' => 1, 'ns1' => 'ns1.a.com', 'ns2' => 'ns2.a.com', 'ns3' => '', 'ns4' => '', 'ns5' => '']);
check('valid save works (incl. notifier, settings, request service)', $save === ['success' => true]);
check('and is returned by GetNameservers', manualreg_GetNameservers(['domainid' => 1])['ns1'] === 'ns1.a.com');

finish();
