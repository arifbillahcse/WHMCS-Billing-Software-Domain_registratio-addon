<?php
/**
 * Dev-only tests added during the full review: numeric settings, orphan cleanup,
 * IDN lookups, row locking, language file, and one end-to-end lifecycle across
 * the registrar module, request service, dashboard, cron and customer notices.
 *
 *   cd tests && composer install && php review_test.php
 */

require __DIR__ . '/bootstrap.php';
require ROOTDIR . '/modules/addons/nsmanager/nsmanager.php';
require ROOTDIR . '/modules/registrars/manualreg/manualreg.php';

use NsManager\AdminController as Admin;
use NsManager\ClientBanner;
use NsManager\Cron;
use NsManager\DnsVerifier;
use NsManager\Repository;
use NsManager\RequestService as RS;
use NsManager\Settings;
use WHMCS\Database\Capsule;

function generate_token($format = 'form')
{
    return 'TESTTOKEN';
}

nsmanager_activate();
Capsule::table('tblclients')->insert(['id' => 7, 'firstname' => 'Ada', 'lastname' => 'Lovelace', 'companyname' => '']);
Capsule::table('tbldomains')->insert([
    ['id' => 1, 'userid' => 7, 'domain' => 'example.com', 'registrar' => 'manualreg', 'status' => 'Active'],
    ['id' => 2, 'userid' => 7, 'domain' => 'gone.net', 'registrar' => 'manualreg', 'status' => 'Active'],
    ['id' => 3, 'userid' => 7, 'domain' => 'moved.org', 'registrar' => 'manualreg', 'status' => 'Active'],
    ['id' => 4, 'userid' => 7, 'domain' => 'kept.io', 'registrar' => 'manualreg', 'status' => 'Active'],
]);

function setting(string $key, string $value): void
{
    Capsule::table('tbladdonmodules')->where(['module' => 'nsmanager', 'setting' => $key])->delete();
    Capsule::table('tbladdonmodules')->insert(['module' => 'nsmanager', 'setting' => $key, 'value' => $value]);
    Settings::reset();
}

echo "numeric settings\n";
setting('rate_limit_per_hour', '');
check('blank falls back to the default (5)', Settings::int('rate_limit_per_hour') === 5);
setting('rate_limit_per_hour', 'abc');
check('non-numeric falls back to the default', Settings::int('rate_limit_per_hour') === 5);
setting('rate_limit_per_hour', '-3');
check('negative clamps to 0', Settings::int('rate_limit_per_hour') === 0);
setting('rate_limit_per_hour', ' 12 ');
check('trimmed number accepted', Settings::int('rate_limit_per_hour') === 12);
setting('rate_limit_per_hour', '0');
check('0 stays 0 (unlimited)', Settings::int('rate_limit_per_hour') === 0);
setting('reminder_hours', '');
check('blank reminder_hours -> 24', Settings::int('reminder_hours') === 24);

echo "blank rate limit behaves like the default in practice\n";
setting('rate_limit_per_hour', '');
$ok = 0;
for ($i = 1; $i <= 7; $i++) {
    $ok += RS::createRequest(4, ["ns1.rl$i.net", "ns2.rl$i.net"], 'client:7')['success'] ? 1 : 0;
}
check('only 5 of 7 rapid client requests allowed', $ok === 5);
setting('rate_limit_per_hour', '0');
Capsule::table('mod_nsmanager_requests')->delete();

echo "orphan cleanup\n";
RS::createRequest(1, ['ns1.keep.net', 'ns2.keep.net'], 'client:7');
RS::createRequest(2, ['ns1.gone.net', 'ns2.gone.net'], 'client:7');
RS::createRequest(3, ['ns1.moved.net', 'ns2.moved.net'], 'client:7');
RS::createRequest(4, ['ns1.k.net', 'ns2.k.net'], 'client:7');
Capsule::table('tbldomains')->where('id', 2)->delete();
Capsule::table('tbldomains')->where('id', 3)->update(['registrar' => 'enom']);
check('two orphans found', count(Repository::pendingOrphans()) === 2);
$GLOBALS['__api_calls'] = [];
check('cancelOrphans cancels exactly those', Cron::cancelOrphans() === 2);
$byDomain = [];
foreach (Repository::listRequests() as $r) {
    $byDomain[$r->domain] = $r;
}
check('deleted domain request cancelled by system', $byDomain['gone.net']->status === 'cancelled' && $byDomain['gone.net']->resolved_by === 'system');
check('moved domain request cancelled', $byDomain['moved.org']->status === 'cancelled');
check('live domains untouched', $byDomain['example.com']->status === 'pending' && $byDomain['kept.io']->status === 'pending');
check('cancelling orphans does not email the customer', array_filter($GLOBALS['__api_calls'], fn ($c) => $c[0] === 'SendEmail') === []);
check('cleanup is repeatable', Cron::cancelOrphans() === 0);

$old = $byDomain['kept.io']->id;
Capsule::table('mod_nsmanager_requests')->where('id', $old)->update(['created_at' => date('Y-m-d H:i:s', time() - 3 * 86400)]);
Capsule::table('tbldomains')->where('id', 4)->delete();
$GLOBALS['__api_calls'] = [];
$res = Cron::sendReminders();
check('reminder run drops orphans first (no nag for deleted domain)', $res === ['overdue' => 0, 'sent' => false] && Repository::findRequest($old)->status === 'cancelled');

echo "IDN / odd domains\n";
DnsVerifier::setResolver(null);
check('unicode domain lookup does not fatal', DnsVerifier::lookup('bücher-' . bin2hex(random_bytes(3)) . '.invalid') === null);

echo "row locking path\n";
Repository::lockDomain(1);
check('lockDomain runs (no-op on SQLite)', true);
check('findRequest with lock', Repository::findRequest((int) $old, true) !== null);

echo "language file\n";
define('WHMCS_LANG_TEST', true);
require ROOTDIR . '/modules/addons/nsmanager/lang/english.php';
check('lang file defines $_ADDONLANG', isset($_ADDONLANG['modulename']));

echo "end-to-end lifecycle\n";
Capsule::table('mod_nsmanager_requests')->delete();
Capsule::table('mod_nsmanager_log')->delete();
Capsule::table('mod_nsmanager_domains')->delete();
setting('notify_admin_email', 'on');
$GLOBALS['__api_calls'] = [];
$_SESSION = ['uid' => 7];
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$vars = ['modulelink' => 'addonmodules.php?module=nsmanager'];

check('1. customer sees empty form first', manualreg_GetNameservers(['domainid' => 1])['ns1'] === '');
check('2. customer saves nameservers', manualreg_SaveNameservers(['domainid' => 1, 'ns1' => 'NS1.NewHost.com', 'ns2' => 'ns2.newhost.com', 'ns3' => '', 'ns4' => '', 'ns5' => '']) === ['success' => true]);
check('3. admins alerted once', count(array_filter($GLOBALS['__api_calls'], fn ($c) => $c[0] === 'SendAdminEmail')) === 1);
check('4. customer gets Update pending banner', strpos(ClientBanner::message(1), 'Update pending') !== false);
check('5. form shows the requested nameservers', manualreg_GetNameservers(['domainid' => 1])['ns1'] === 'ns1.newhost.com');

$html = Admin::handle($vars, [], [], 'admin:9', false);
check('6. admin queue lists it', strpos($html, 'example.com') !== false && strpos($html, 'ns1.newhost.com') !== false && strpos($html, '198.51.100.7') !== false);
$id = (int) Repository::findPendingByDomain(1)->id;

$GLOBALS['__api_calls'] = [];
$html = Admin::handle($vars, [], ['bulk' => 'apply', 'ids' => [(string) $id]], 'admin:9', true);
check('7. admin applies it (bulk)', strpos($html, '1 request(s) marked as applied') !== false);
check('8. customer emailed', count(array_filter($GLOBALS['__api_calls'], fn ($c) => $c[0] === 'SendEmail')) === 1);
check('9. banner gone, form shows live nameservers', ClientBanner::message(1) === '' && manualreg_GetNameservers(['domainid' => 1])['ns2'] === 'ns2.newhost.com');
check('10. domain page shows the full history', ($h = Admin::handle($vars, ['action' => 'domain', 'id' => 1], [], 'admin:9', false)) && strpos($h, 'created') !== false && strpos($h, 'applied') !== false && strpos($h, 'admin:9') !== false);

manualreg_SaveNameservers(['domainid' => 1, 'ns1' => 'ns1.third.com', 'ns2' => 'ns2.third.com', 'ns3' => '', 'ns4' => '', 'ns5' => '']);
DnsVerifier::setResolver(fn ($d) => ['ns1.third.com', 'ns2.third.com']);
Capsule::table('mod_nsmanager_requests')->update(['last_checked_at' => null]);
$GLOBALS['__api_calls'] = [];
$res = Cron::verifyPending();
check('11. next change closes itself via DNS verify', $res['applied'] === 1 && count(array_filter($GLOBALS['__api_calls'], fn ($c) => $c[0] === 'SendEmail')) === 1);
check('12. nameservers history intact', count(Repository::getRequestsByDomain(1)) === 2 && Repository::getAppliedNs(1) === ['ns1.third.com', 'ns2.third.com']);

finish();
