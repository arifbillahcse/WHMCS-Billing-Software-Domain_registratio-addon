<?php
/**
 * Dev-only test for the admin dashboard (AdminController + templates).
 *
 *   cd tests && composer install && php admin_test.php
 */

require __DIR__ . '/bootstrap.php';
require ROOTDIR . '/modules/addons/nsmanager/nsmanager.php';

use NsManager\AdminController as Admin;
use NsManager\Repository;
use NsManager\RequestService as RS;
use WHMCS\Database\Capsule;

function generate_token($format = 'form')
{
    return 'TESTTOKEN';
}

nsmanager_activate();

Capsule::table('tblclients')->insert([
    ['id' => 7, 'firstname' => 'Ada', 'lastname' => 'Lovelace', 'companyname' => 'Analytical <b>Ltd</b>'],
    ['id' => 8, 'firstname' => 'Bob', 'lastname' => 'Ray', 'companyname' => ''],
]);
Capsule::table('tbldomains')->insert([
    ['id' => 1, 'userid' => 7, 'domain' => 'example.com', 'registrar' => 'manualreg', 'status' => 'Active'],
    ['id' => 2, 'userid' => 8, 'domain' => 'other.net', 'registrar' => 'manualreg', 'status' => 'Active'],
    ['id' => 3, 'userid' => 8, 'domain' => 'api-managed.org', 'registrar' => 'enom', 'status' => 'Active'],
    ['id' => 4, 'userid' => 7, 'domain' => '<script>alert(1)</script>.com', 'registrar' => 'manualreg', 'status' => 'Active'],
]);

$vars = ['modulelink' => 'addonmodules.php?module=nsmanager'];
function page(array $get = [], array $post = [], string $actor = 'admin:1'): string
{
    global $vars;
    return Admin::handle($vars, $get, $post, $actor, $post !== []);
}

echo "empty states\n";
$html = page();
check('pending tab empty message', strpos($html, 'No pending requests') !== false);
check('tabs rendered', strpos($html, 'tab=providers') !== false && strpos($html, 'tab=failed') !== false);

echo "queue rendering\n";
RS::createRequest(1, ['ns1.old.com', 'ns2.old.com'], 'client:7');
RS::markApplied(1, 'admin:1');
$r2 = RS::createRequest(1, ['ns1.old.com', 'ns3.new.com'], 'client:7', '203.0.113.9');
$r3 = RS::createRequest(2, ['ns1.b.com', 'ns2.b.com'], 'client:8');
$html = page();
check('pending badge shows 2', strpos($html, 'badge-count">2<') !== false);
check('domain linked to detail page', strpos($html, 'action=domain&amp;id=1') !== false);
check('customer name shown + escaped company', strpos($html, 'Ada Lovelace (Analytical &lt;b&gt;Ltd&lt;/b&gt;)') !== false && strpos($html, '<b>Ltd</b>') === false);
check('new ns highlighted, old kept struck', strpos($html, 'class="ns-new">ns3.new.com') !== false && strpos($html, 'class="ns-gone">ns2.old.com') !== false);
check('unchanged ns not highlighted', strpos($html, 'class="">ns1.old.com') !== false);
check('requester ip + actor shown', strpos($html, 'client:7') !== false && strpos($html, '203.0.113.9') !== false);
check('csrf token in form', strpos($html, 'name="token" value="TESTTOKEN"') !== false);
check('row action buttons', strpos($html, 'value="apply:' . $r2['request_id'] . '"') !== false && strpos($html, 'value="fail:') !== false && strpos($html, 'value="cancel:') !== false);
check('bulk button + disabled default button', strpos($html, 'name="bulk" value="apply"') !== false && strpos($html, 'type="submit" disabled hidden') !== false);
check('copy button carries new ns', strpos($html, 'data-copy="ns1.old.com&#10;ns3.new.com"') !== false || strpos($html, "data-copy=\"ns1.old.com\nns3.new.com\"") !== false);
check('no provider -> set provider link', strpos($html, 'set provider') !== false);
check('applied tab does not list pending', strpos(page(['tab' => 'applied']), 'ns3.new.com') === false);
check('bad tab falls back to pending', strpos(page(['tab' => 'nonsense']), 'ns3.new.com') !== false);

echo "stale highlighting\n";
Capsule::table('mod_nsmanager_requests')->where('id', $r3['request_id'])->update(['created_at' => date('Y-m-d H:i:s', time() - 100000)]);
$html = page();
check('old request flagged stale', strpos($html, 'class="stale"') !== false);

echo "actions\n";
$html = page([], ['row_action' => 'apply:' . $r2['request_id'], 'reason' => ' checked at registrar ']);
check('apply flash', strpos($html, 'marked as applied') !== false);
check('apply persisted with note', ($q = Repository::findRequest($r2['request_id']))->status === 'applied' && $q->admin_note === 'checked at registrar' && $q->resolved_by === 'admin:1');
$html = page([], ['row_action' => 'apply:' . $r2['request_id']]);
check('double apply -> error flash', strpos($html, 'alert-danger') !== false && strpos($html, 'already applied') !== false);

$html = page([], ['row_action' => 'fail:' . $r3['request_id'], 'reason' => '']);
check('fail without reason rejected', strpos($html, 'reason is required') !== false && Repository::findRequest($r3['request_id'])->status === 'pending');
page([], ['row_action' => 'fail:' . $r3['request_id'], 'reason' => 'Domain is locked at provider']);
check('fail with reason saved', ($q = Repository::findRequest($r3['request_id']))->status === 'failed' && $q->admin_note === 'Domain is locked at provider');
check('failed tab shows reason', strpos(page(['tab' => 'failed']), 'Domain is locked at provider') !== false);

$r4 = RS::createRequest(2, ['ns1.c.com', 'ns2.c.com'], 'client:8');
page([], ['row_action' => 'cancel:' . $r4['request_id']]);
check('cancel works', Repository::findRequest($r4['request_id'])->status === 'cancelled');
check('all tab lists every status', ($h = page(['tab' => 'all'])) && strpos($h, 'cancelled') !== false && strpos($h, 'failed') !== false && strpos($h, 'applied') !== false);

check('garbage row_action ignored', strpos(page([], ['row_action' => 'delete:1']), 'alert-') === false);

echo "bulk apply\n";
$a = RS::createRequest(1, ['ns1.bulk.com', 'ns2.bulk.com'], 'client:7');
$b = RS::createRequest(2, ['ns1.bulk.com', 'ns2.bulk.com'], 'client:8');
$html = page([], ['bulk' => 'apply', 'ids' => [(string) $a['request_id'], (string) $b['request_id'], '9999', 'abc']]);
check('both applied', Repository::findRequest($a['request_id'])->status === 'applied' && Repository::findRequest($b['request_id'])->status === 'applied');
check('summary + error for unknown id', strpos($html, '2 request(s) marked as applied') !== false && strpos($html, 'Request not found') !== false);
check('empty selection warns', strpos(page([], ['bulk' => 'apply']), 'No requests selected') !== false);

echo "domain page + provider details\n";
$html = page(['action' => 'domain', 'id' => 1]);
check('domain page shows history + audit', strpos($html, 'Audit history') !== false && strpos($html, 'created') !== false && strpos($html, 'applied') !== false);
check('unknown domain', strpos(page(['action' => 'domain', 'id' => 999]), 'Domain not found') !== false);

$html = page(['action' => 'domain', 'id' => 1], [
    'save_domain' => '1', 'domain_id' => '1', 'provider' => 'Namecheap',
    'provider_login_url' => 'https://ap.www.namecheap.com/', 'account_label' => 'main <acct>',
    'notes' => 'renew in March', 'applied_ns' => "NS1.Live.com\nns2.live.com",
]);
check('save flash', strpos($html, 'Domain details saved') !== false);
$m = Repository::getDomainMeta(1);
check('meta stored', $m->provider === 'Namecheap' && $m->notes === 'renew in March');
check('live ns stored normalized', Repository::getAppliedNs(1) === ['ns1.live.com', 'ns2.live.com']);
check('audit logged applied_ns_set', in_array('applied_ns_set', array_map(fn ($l) => $l->event, Repository::getLog(1)), true));
check('label escaped', strpos($html, 'main &lt;acct&gt;') !== false && strpos($html, 'main <acct>') === false);
check('provider link opens safely', strpos($html, 'rel="noopener noreferrer"') !== false);

$html = page(['action' => 'domain', 'id' => 1], ['save_domain' => '1', 'domain_id' => '1', 'provider' => 'X', 'provider_login_url' => 'javascript:alert(1)', 'applied_ns' => '']);
check('javascript: url rejected', strpos($html, 'must start with http') !== false && Repository::getDomainMeta(1)->provider === 'Namecheap');
$html = page(['action' => 'domain', 'id' => 1], ['save_domain' => '1', 'domain_id' => '1', 'provider' => 'X', 'applied_ns' => 'only-one.example.com']);
check('invalid live ns rejected', strpos($html, 'Live nameservers: At least 2') !== false && Repository::getAppliedNs(1) === ['ns1.live.com', 'ns2.live.com']);
page(['action' => 'domain', 'id' => 1], ['save_domain' => '1', 'domain_id' => '1', 'provider' => str_repeat('p', 300)]);
check('provider length capped at 100', strlen(Repository::getDomainMeta(1)->provider) === 100);
check('missing applied_ns field leaves live ns alone', Repository::getAppliedNs(1) === ['ns1.live.com', 'ns2.live.com']);
Repository::upsertDomainMeta(1, ['provider' => 'Namecheap', 'provider_login_url' => 'https://ap.www.namecheap.com/']);
check('unknown domain save rejected', strpos(page([], ['save_domain' => '1', 'domain_id' => '999']), 'Domain not found') !== false);

$next = RS::createRequest(1, ['ns1.after.com', 'ns2.after.com'], 'client:7');
check('new request uses saved live ns as old', Repository::decodeNs(Repository::findRequest($next['request_id'])->old_ns) === ['ns1.live.com', 'ns2.live.com']);
$html = page();
check('queue shows provider link once set', strpos($html, 'href="https://ap.www.namecheap.com/"') !== false);

echo "providers tab\n";
$html = page(['tab' => 'providers']);
check('lists only manualreg domains', strpos($html, 'example.com') !== false && strpos($html, 'other.net') !== false && strpos($html, 'api-managed.org') === false);
check('domain name escaped', strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;.com') !== false && strpos($html, '<script>alert(1)') === false);
check('pending label shown', strpos($html, 'label-warning">pending') !== false);
$html = page(['tab' => 'providers', 'q' => 'namecheap']);
check('search by provider', strpos($html, 'example.com') !== false && strpos($html, 'other.net') === false);
$html = page(['tab' => 'providers', 'q' => 'zzz']);
check('search no results', strpos($html, 'matching that search') !== false);

echo "pagination\n";
Capsule::table('mod_nsmanager_requests')->delete();
for ($i = 1; $i <= 30; $i++) {
    Repository::insertRequest([
        'domain_id' => 1, 'domain' => 'example.com', 'client_id' => 7, 'old_ns' => '[]',
        'new_ns' => json_encode(["ns1.p$i.com", "ns2.p$i.com"]), 'status' => 'applied', 'requested_by' => 'client:7',
    ]);
}
$html = page(['tab' => 'applied']);
check('page 1 has 25 rows + next link', substr_count($html, 'ns-new">ns1.p') === 25 && strpos($html, 'Page 1 of 2') !== false && strpos($html, 'Next') !== false);
$html = page(['tab' => 'applied', 'page' => 2]);
check('page 2 has remainder + previous link', substr_count($html, 'ns-new">ns1.p') === 5 && strpos($html, 'Previous') !== false);
check('page 0 clamps to 1', strpos(page(['tab' => 'applied', 'page' => 0]), 'Page 1 of 2') !== false);

echo "output wrapper\n";
$_SESSION = ['adminid' => 5];
$_GET = ['tab' => 'applied'];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
nsmanager_output($vars);
$out = ob_get_clean();
check('nsmanager_output renders dashboard', strpos($out, 'class="nsm"') !== false);

finish();
