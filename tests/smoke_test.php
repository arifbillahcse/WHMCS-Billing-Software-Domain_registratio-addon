<?php
/**
 * Dev-only smoke test. Runs outside WHMCS using illuminate/database + SQLite,
 * aliasing Capsule to WHMCS\Database\Capsule.
 *
 *   cd tests && composer install && php smoke_test.php
 */

require __DIR__ . '/bootstrap.php';
require ROOTDIR . '/modules/addons/nsmanager/nsmanager.php';

use NsManager\Repository;
use NsManager\RequestService as RS;
use NsManager\Validator;
use WHMCS\Database\Capsule;

Capsule::table('tbldomains')->insert(['id' => 1, 'userid' => 7, 'domain' => 'example.com']);

echo "activate\n";
$r = nsmanager_activate();
check('activate succeeds', $r['status'] === 'success');
check('activate is idempotent', nsmanager_activate()['status'] === 'success');
foreach (['mod_nsmanager_requests', 'mod_nsmanager_domains', 'mod_nsmanager_log'] as $tbl) {
    check("table $tbl exists", Capsule::schema()->hasTable($tbl));
}

echo "validator\n";
check('normalizes case/dot/space', Validator::normalize([' NS1.Example.com. ', '', 'ns2.example.com']) === ['ns1.example.com', 'ns2.example.com']);
check('valid pair', Validator::validate(['ns1.a.com', 'ns2.a.com'])['ok']);
check('rejects 1 ns', !Validator::validate(['ns1.a.com'])['ok']);
check('rejects 6 ns', !Validator::validate(['a.a.com', 'b.a.com', 'c.a.com', 'd.a.com', 'e.a.com', 'f.a.com'])['ok']);
check('rejects duplicates', !Validator::validate(['ns1.a.com', 'NS1.a.com.'])['ok']);
check('rejects IP', !Validator::validate(['ns1.a.com', '1.2.3.4'])['ok']);
check('rejects no dot', !Validator::validate(['ns1.a.com', 'localhost'])['ok']);
check('rejects bad chars', !Validator::validate(['ns1.a.com', 'ns_2.a.com'])['ok']);
check('rejects leading hyphen', !Validator::validate(['ns1.a.com', '-ns2.a.com'])['ok']);

echo "createRequest\n";
$bad = RS::createRequest(1, ['ns1.a.com'], 'client:7');
check('invalid ns rejected', !$bad['success']);
$bad = RS::createRequest(999, ['ns1.a.com', 'ns2.a.com'], 'client:7');
check('unknown domain rejected', !$bad['success']);

$a = RS::createRequest(1, ['NS1.Host.com', 'ns2.host.com'], 'client:7', '203.0.113.9');
check('first request created', $a['success'] && !$a['noop'] && $a['request_id'] === 1);
$row = Repository::findRequest(1);
check('row is pending with client/domain', $row->status === 'pending' && (int) $row->client_id === 7 && $row->domain === 'example.com');
check('currentNameservers shows pending', RS::currentNameservers(1) === ['ns1.host.com', 'ns2.host.com']);

$same = RS::createRequest(1, ['ns1.host.com', 'ns2.host.com'], 'client:7');
check('same as pending is noop', $same['success'] && $same['noop'] && $same['request_id'] === 1);

$b = RS::createRequest(1, ['ns1.other.com', 'ns2.other.com'], 'client:7');
check('new request supersedes old', $b['success'] && $b['superseded_id'] === 1 && $b['request_id'] === 2);
check('old row superseded', Repository::findRequest(1)->status === 'superseded');
check('one pending per domain', Repository::findPendingByDomain(1)->id == 2);

echo "markApplied\n";
$ap = RS::markApplied(2, 'admin:1', 'done at registrar');
check('applied ok', $ap['success']);
check('status applied + resolved', ($q = Repository::findRequest(2))->status === 'applied' && $q->resolved_by === 'admin:1' && $q->resolved_at !== null);
check('applied_ns stored', Repository::getAppliedNs(1) === ['ns1.other.com', 'ns2.other.com']);
check('currentNameservers = applied', RS::currentNameservers(1) === ['ns1.other.com', 'ns2.other.com']);
check('cannot apply twice', !RS::markApplied(2, 'admin:1')['success']);

$noop = RS::createRequest(1, ['ns1.other.com', 'ns2.other.com'], 'client:7');
check('same as applied is noop', $noop['success'] && $noop['noop'] && $noop['request_id'] === null);

echo "markFailed / cancel / revert\n";
$c = RS::createRequest(1, ['ns1.new.com', 'ns2.new.com'], 'client:7');
check('old_ns captured from applied', Repository::decodeNs(Repository::findRequest($c['request_id'])->old_ns) === ['ns1.other.com', 'ns2.other.com']);
check('failed needs reason', !RS::markFailed($c['request_id'], 'admin:1', '  ')['success']);
check('failed ok', RS::markFailed($c['request_id'], 'admin:1', 'provider locked domain')['success']);
check('failed keeps applied_ns', Repository::getAppliedNs(1) === ['ns1.other.com', 'ns2.other.com']);

$d = RS::createRequest(1, ['ns1.x.com', 'ns2.x.com'], 'client:7');
check('cancel ok', RS::cancel($d['request_id'], 'client:7')['success']);
check('cancelled status', Repository::findRequest($d['request_id'])->status === 'cancelled');

$e = RS::createRequest(1, ['ns1.y.com', 'ns2.y.com'], 'client:7');
$rev = RS::createRequest(1, ['ns1.other.com', 'ns2.other.com'], 'client:7');
check('revert to applied withdraws pending', $rev['noop'] && $rev['superseded_id'] === $e['request_id'] && Repository::findPendingByDomain(1) === null);

echo "audit / listing\n";
$events = array_map(fn ($l) => $l->event, Repository::getLog(1));
check('audit has created/applied/failed/cancelled/superseded', count(array_intersect(['created', 'applied', 'failed', 'cancelled', 'superseded'], $events)) === 5);
check('log per request', count(Repository::getLog(null, 2)) >= 2);
$counts = Repository::countByStatus();
check('countByStatus', ($counts['applied'] ?? 0) === 1 && ($counts['failed'] ?? 0) === 1 && ($counts['cancelled'] ?? 0) === 1);
check('listRequests filter', count(Repository::listRequests('applied')) === 1);

echo "domain meta\n";
Repository::upsertDomainMeta(1, ['provider' => 'Namecheap']);
Repository::upsertDomainMeta(1, ['account_label' => 'main']);
$m = Repository::getDomainMeta(1);
check('meta upsert keeps fields', $m->provider === 'Namecheap' && $m->account_label === 'main' && Repository::getAppliedNs(1) !== []);

echo "pendingOlderThan\n";
$p = RS::createRequest(1, ['ns1.z.com', 'ns2.z.com'], 'client:7');
check('fresh request not stale', Repository::pendingOlderThan(date('Y-m-d H:i:s', time() - 86400)) === []);
Capsule::table('mod_nsmanager_requests')->where('id', $p['request_id'])->update(['created_at' => date('Y-m-d H:i:s', time() - 90000)]);
check('old request stale', count(Repository::pendingOlderThan(date('Y-m-d H:i:s', time() - 86400))) === 1);

echo "upgrade / deactivate\n";
nsmanager_upgrade([]);
check('deactivate keeps data', nsmanager_deactivate()['status'] === 'success' && Capsule::schema()->hasTable('mod_nsmanager_requests'));

finish();
