<?php
/**
 * Dev-only test for Phase 5: upgrade, reminders, DNS auto-verify, client banner,
 * rate limit, glue warning and hook registration.
 *
 *   cd tests && composer install && php phase5_test.php
 */

require __DIR__ . '/bootstrap.php';

use NsManager\ClientBanner;
use NsManager\Cron;
use NsManager\DnsVerifier;
use NsManager\Notifier;
use NsManager\Repository;
use NsManager\RequestService as RS;
use NsManager\Settings;
use NsManager\Validator;
use WHMCS\Database\Capsule;

echo "upgrade from 0.1.0\n";
// Old schema: no last_checked_at.
Capsule::schema()->create('mod_nsmanager_requests', function ($t) {
    $t->increments('id');
    $t->unsignedInteger('domain_id');
    $t->string('domain');
    $t->unsignedInteger('client_id');
    $t->text('old_ns')->nullable();
    $t->text('new_ns');
    $t->string('status', 20)->default('pending');
    $t->string('requested_by', 64);
    $t->string('requester_ip', 45)->nullable();
    $t->text('admin_note')->nullable();
    $t->string('resolved_by', 64)->nullable();
    $t->timestamp('resolved_at')->nullable();
    $t->timestamp('last_reminded_at')->nullable();
    $t->timestamp('created_at')->nullable();
    $t->timestamp('updated_at')->nullable();
});
Capsule::table('mod_nsmanager_requests')->insert(['domain_id' => 1, 'domain' => 'legacy.com', 'client_id' => 1, 'new_ns' => '[]', 'requested_by' => 'client:1']);
check('column missing before upgrade', !Capsule::schema()->hasColumn('mod_nsmanager_requests', 'last_checked_at'));
require ROOTDIR . '/modules/addons/nsmanager/nsmanager.php';
nsmanager_upgrade([]);
check('upgrade adds the column', Capsule::schema()->hasColumn('mod_nsmanager_requests', 'last_checked_at'));
check('upgrade keeps existing rows', Capsule::table('mod_nsmanager_requests')->where('domain', 'legacy.com')->count() === 1);
nsmanager_upgrade([]);
check('upgrade is idempotent', true);
check('config version bumped', nsmanager_config()['version'] === '0.2.0');
Capsule::table('mod_nsmanager_requests')->delete();
nsmanager_activate();

Capsule::table('tblclients')->insert(['id' => 7, 'firstname' => 'Ada', 'lastname' => 'Lovelace', 'companyname' => '']);
Capsule::table('tbldomains')->insert([
    ['id' => 1, 'userid' => 7, 'domain' => 'example.com', 'registrar' => 'manualreg', 'status' => 'Active'],
    ['id' => 2, 'userid' => 7, 'domain' => 'second.org', 'registrar' => 'manualreg', 'status' => 'Active'],
    ['id' => 3, 'userid' => 7, 'domain' => 'enom.net', 'registrar' => 'enom', 'status' => 'Active'],
]);

function setting(string $key, string $value): void
{
    Capsule::table('tbladdonmodules')->where(['module' => 'nsmanager', 'setting' => $key])->delete();
    Capsule::table('tbladdonmodules')->insert(['module' => 'nsmanager', 'setting' => $key, 'value' => $value]);
    Settings::reset();
}

function calls(string $command): array
{
    return array_values(array_filter($GLOBALS['__api_calls'], fn ($c) => $c[0] === $command));
}

function backdate(int $id, int $secondsAgo, string $column = 'created_at'): void
{
    Capsule::table('mod_nsmanager_requests')->where('id', $id)->update([$column => date('Y-m-d H:i:s', time() - $secondsAgo)]);
}

echo "glue detection\n";
check('child nameserver needs glue', Validator::glueHosts('example.com', ['ns1.example.com', 'ns1.other.net']) === ['ns1.example.com']);
check('deeper subdomain needs glue', Validator::glueHosts('example.com', ['a.b.example.com']) === ['a.b.example.com']);
check('lookalike domain does not', Validator::glueHosts('example.com', ['ns1.myexample.com', 'example.com.evil.net']) === []);
check('case-insensitive', Validator::glueHosts('Example.COM.', ['NS1.example.com']) === ['NS1.example.com']);
$GLOBALS['__api_calls'] = [];
RS::createRequest(1, ['ns1.example.com', 'ns2.example.com'], 'client:7');
check('admin alert warns about glue', strpos(calls('SendAdminEmail')[0][1]['custommessage'], 'create the child nameserver') !== false);
$GLOBALS['__api_calls'] = [];
RS::createRequest(1, ['ns1.fine.net', 'ns2.fine.net'], 'client:7');
check('no warning without glue', strpos(calls('SendAdminEmail')[0][1]['custommessage'], 'WARNING') === false);
$pendingId = Repository::findPendingByDomain(1)->id;

echo "rate limit\n";
setting('rate_limit_per_hour', '3');
// 2 client requests already exist for domain 1 in the last hour.
$r = RS::createRequest(1, ['ns1.rl1.net', 'ns2.rl1.net'], 'client:7');
check('3rd request within the hour allowed', $r['success']);
$r = RS::createRequest(1, ['ns1.rl2.net', 'ns2.rl2.net'], 'client:7');
check('4th request blocked', !$r['success'] && strpos($r['error'], 'Too many') === 0);
check('blocked request leaves pending untouched', Repository::findPendingByDomain(1)->new_ns === Repository::encodeNs(['ns1.rl1.net', 'ns2.rl1.net']));
check('admin not rate limited', RS::createRequest(1, ['ns1.rl3.net', 'ns2.rl3.net'], 'admin:1')['success']);
check('system not rate limited', RS::createRequest(1, ['ns1.rl4.net', 'ns2.rl4.net'], 'system')['success']);
check('other domains unaffected', RS::createRequest(2, ['ns1.rl5.net', 'ns2.rl5.net'], 'client:7')['success']);
check('noop is never blocked', RS::createRequest(1, ['ns1.rl4.net', 'ns2.rl4.net'], 'client:7')['success']);
Capsule::table('mod_nsmanager_requests')->where('domain_id', 1)->update(['created_at' => date('Y-m-d H:i:s', time() - 7200)]);
check('window rolls over after an hour', RS::createRequest(1, ['ns1.rl6.net', 'ns2.rl6.net'], 'client:7')['success']);
setting('rate_limit_per_hour', '0');
check('0 means unlimited', RS::createRequest(1, ['ns1.rl7.net', 'ns2.rl7.net'], 'client:7')['success'] && RS::createRequest(1, ['ns1.rl8.net', 'ns2.rl8.net'], 'client:7')['success']);

// Clean slate for the remaining sections.
Capsule::table('mod_nsmanager_requests')->delete();
Capsule::table('mod_nsmanager_domains')->delete();
Capsule::table('mod_nsmanager_log')->delete();

echo "reminders\n";
check('nothing pending -> nothing sent', Cron::sendReminders() === ['overdue' => 0, 'sent' => false]);
$fresh = RS::createRequest(1, ['ns1.new.net', 'ns2.new.net'], 'client:7')['request_id'];
$old = RS::createRequest(2, ['ns1.old.net', 'ns2.old.net'], 'client:7')['request_id'];
Repository::upsertDomainMeta(2, ['provider' => 'GoDaddy']);
backdate($old, 3 * 86400);
$GLOBALS['__api_calls'] = [];
$res = Cron::sendReminders();
check('only the overdue request counted', $res === ['overdue' => 1, 'sent' => true]);
$digest = calls('SendAdminEmail');
check('one digest sent', count($digest) === 1);
check('digest lists request, age, provider, link', strpos($digest[0][1]['custommessage'], 'second.org') !== false && strpos($digest[0][1]['custommessage'], '3d') !== false && strpos($digest[0][1]['custommessage'], 'GoDaddy') !== false && strpos($digest[0][1]['custommessage'], 'addonmodules.php?module=nsmanager') !== false);
check('fresh request not in digest', strpos($digest[0][1]['custommessage'], '#' . $fresh . ' example.com') === false);
check('reminded timestamp stored', Repository::findRequest($old)->last_reminded_at !== null && Repository::findRequest($fresh)->last_reminded_at === null);
$GLOBALS['__api_calls'] = [];
check('no repeat the same day', Cron::sendReminders() === ['overdue' => 0, 'sent' => false] && $GLOBALS['__api_calls'] === []);
backdate($old, 90000, 'last_reminded_at');
check('reminds again after a day', Cron::sendReminders()['sent'] === true);

backdate($old, 90000, 'last_reminded_at');
setting('notify_admin_email', '');
$res = Cron::sendReminders();
check('no channel enabled -> not marked as reminded', $res['overdue'] === 1 && $res['sent'] === false && Repository::findRequest($old)->last_reminded_at <= date('Y-m-d H:i:s', time() - 86000));
setting('notify_admin_email', 'on');
setting('reminder_hours', '72');
check('reminder window configurable', Cron::sendReminders()['overdue'] === 0);
setting('reminder_hours', '1');
backdate($fresh, 7200);
backdate($old, 90000, 'last_reminded_at');
check('window of 1h catches both', Cron::sendReminders()['overdue'] === 2);
setting('reminder_hours', '24');

echo "DNS auto-verify\n";
Capsule::table('mod_nsmanager_requests')->delete();
$a = RS::createRequest(1, ['ns2.live.net', 'ns1.live.net'], 'client:7')['request_id'];
$b = RS::createRequest(2, ['ns1.wait.net', 'ns2.wait.net'], 'client:7')['request_id'];
$dns = ['example.com' => ['ns1.live.net', 'ns2.live.net'], 'second.org' => ['ns1.stale.net', 'ns2.stale.net']];
DnsVerifier::setResolver(function ($domain) use (&$dns) {
    return $dns[$domain] ?? null;
});
$GLOBALS['__api_calls'] = [];
$res = Cron::verifyPending();
check('one applied, one waiting', $res === ['checked' => 2, 'applied' => 1, 'waiting' => 1, 'errors' => 0]);
check('match closes the request as system', ($q = Repository::findRequest($a))->status === 'applied' && $q->resolved_by === 'system' && strpos($q->admin_note, 'Verified automatically') === 0);
check('order of nameservers does not matter / applied_ns stored', Repository::getAppliedNs(1) === ['ns2.live.net', 'ns1.live.net']);
check('customer told about the auto-apply', count(calls('SendEmail')) === 1);
check('mismatch stays pending, never failed', Repository::findRequest($b)->status === 'pending');
check('check time recorded', Repository::findRequest($b)->last_checked_at !== null);

$GLOBALS['__api_calls'] = [];
check('throttled: not re-checked within 15 min', Cron::verifyPending()['checked'] === 0);
backdate($b, 1000, 'last_checked_at');
check('still throttled at ~16 min? (1000s = 16.7 min, so due)', Cron::verifyPending()['checked'] === 1);
backdate($b, 300, 'last_checked_at');
check('not due at 5 min', Cron::verifyPending()['checked'] === 0);

$dns['second.org'] = ['ns1.wait.net', 'ns2.wait.net'];
backdate($b, 1000, 'last_checked_at');
check('closes once DNS catches up', Cron::verifyPending()['applied'] === 1 && Repository::findRequest($b)->status === 'applied');

echo "lookup failures and settings\n";
$c = RS::createRequest(1, ['ns1.err.net', 'ns2.err.net'], 'client:7')['request_id'];
DnsVerifier::setResolver(fn ($domain) => null);
check('lookup failure counted, request untouched', Cron::verifyPending() === ['checked' => 1, 'applied' => 0, 'waiting' => 0, 'errors' => 1] && Repository::findRequest($c)->status === 'pending');
backdate($c, 1000, 'last_checked_at');
setting('auto_verify_dns', '');
check('setting off -> no checks', Cron::verifyPending()['checked'] === 0);
setting('auto_verify_dns', 'on');
DnsVerifier::setResolver(function ($d) {
    throw new RuntimeException('resolver exploded');
});
check('resolver exception never escapes', is_array(Cron::verifyPending()));
check('real lookup of a bogus name returns null', (function () {
    DnsVerifier::setResolver(null);
    return DnsVerifier::lookup('nonexistent-' . bin2hex(random_bytes(4)) . '.invalid') === null;
})());

echo "client banner\n";
Capsule::table('mod_nsmanager_requests')->delete();
check('no requests -> no banner', ClientBanner::render('clientareadomaindetails', 1, 7) === '');
$p = RS::createRequest(1, ['ns1.ban.net', 'ns2.ban.net'], 'client:7')['request_id'];
$html = ClientBanner::render('clientareadomaindetails', 1, 7);
check('pending -> banner script', strpos($html, '<script>') === 0 && strpos($html, 'Update pending') !== false && strpos($html, 'ns1.ban.net, ns2.ban.net') !== false);
check('wrong page -> nothing', ClientBanner::render('clientareahome', 1, 7) === '');
check("other client's domain -> nothing", ClientBanner::render('clientareadomaindetails', 1, 99) === '');
check('not logged in -> nothing', ClientBanner::render('clientareadomaindetails', 1, 0) === '');
check('non-manual registrar -> nothing', ClientBanner::render('clientareadomaindetails', 3, 7) === '');
check('script cannot be broken out of', substr_count($html, '</script>') === 1);

RS::markFailed($p, 'admin:1', 'Locked </script><img src=x onerror=alert(1)> {$x}');
$html = ClientBanner::render('clientareadomaindetails', 1, 7);
check('failed -> red banner with reason', strpos($html, 'could not be completed') !== false);
check('reason escaped inside the script', substr_count($html, '</script>') === 1 && strpos($html, '<img') === false && strpos($html, 'u0026lt;img') !== false);
$m = ClientBanner::message(1);
check('message html escapes the reason', strpos($m, '&lt;img src=x onerror=alert(1)&gt;') !== false && strpos($m, '<img') === false);
backdate($p, 10 * 86400, 'resolved_at');
check('old failure disappears after 7 days', ClientBanner::render('clientareadomaindetails', 1, 7) === '');
$q = RS::createRequest(1, ['ns1.ok.net', 'ns2.ok.net'], 'client:7')['request_id'];
RS::markApplied($q, 'admin:1');
check('applied -> no banner', ClientBanner::render('clientareadomaindetails', 1, 7) === '');

echo "hooks\n";
$registered = [];
function add_hook($name, $priority, $fn)
{
    $GLOBALS['__hooks'][$name][] = $fn;
}
$GLOBALS['__hooks'] = [];
require ROOTDIR . '/includes/hooks/nsmanager.php';
check('three hooks registered', array_keys($GLOBALS['__hooks']) === ['AfterCronJob', 'DailyCronJob', 'ClientAreaFooterOutput']);
check('boot ok with tables present', nsmanager_hooks_boot() === true);

$p2 = RS::createRequest(1, ['ns1.hook.net', 'ns2.hook.net'], 'client:7')['request_id'];
$_SESSION = ['uid' => 7];
$out = $GLOBALS['__hooks']['ClientAreaFooterOutput'][0](['templatefile' => 'clientareadomaindetails', 'domainid' => 1]);
check('footer hook outputs banner on domain page', strpos($out, 'Update pending') !== false);
$_GET = ['id' => '1'];
$out = $GLOBALS['__hooks']['ClientAreaFooterOutput'][0](['templatefile' => 'clientareadomaindetails']);
check('footer hook falls back to ?id=', strpos($out, 'Update pending') !== false);
check('footer hook silent elsewhere', $GLOBALS['__hooks']['ClientAreaFooterOutput'][0](['templatefile' => 'homepage']) === '');

DnsVerifier::setResolver(fn ($d) => ['ns1.hook.net', 'ns2.hook.net']);
backdate($p2, 1000, 'last_checked_at');
$GLOBALS['__hooks']['AfterCronJob'][0]([]);
check('AfterCronJob hook auto-verifies', Repository::findRequest($p2)->status === 'applied');

$old = RS::createRequest(1, ['ns1.rem.net', 'ns2.rem.net'], 'client:7')['request_id'];
backdate($old, 3 * 86400);
$GLOBALS['__api_calls'] = [];
$GLOBALS['__hooks']['DailyCronJob'][0]([]);
check('DailyCronJob hook sends the digest', count(calls('SendAdminEmail')) === 1);

finish();
