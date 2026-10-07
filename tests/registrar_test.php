<?php
/**
 * Dev-only test for the manualreg registrar module.
 *
 *   cd tests && composer install && php registrar_test.php
 */

require __DIR__ . '/bootstrap.php';
require ROOTDIR . '/modules/addons/nsmanager/nsmanager.php';
require ROOTDIR . '/modules/registrars/manualreg/manualreg.php';

use NsManager\Repository;
use WHMCS\Database\Capsule;

Capsule::table('tbldomains')->insert([
    ['id' => 1, 'userid' => 7, 'domain' => 'example.com'],
    ['id' => 2, 'userid' => 8, 'domain' => 'other.net'],
]);

function params(int $domainId, array $ns = []): array
{
    $p = ['domainid' => $domainId];
    for ($i = 1; $i <= 5; $i++) {
        $p['ns' . $i] = $ns[$i - 1] ?? '';
    }
    return $p;
}

echo "module metadata\n";
check('MetaData', manualreg_MetaData()['DisplayName'] === 'Manual Registrar');
check('config array', isset(manualreg_getConfigArray()['FriendlyName']));
check('register/transfer/renew succeed', manualreg_RegisterDomain([])['success'] && manualreg_TransferDomain([])['success'] && manualreg_RenewDomain([])['success']);

echo "addon not activated\n";
$_SESSION = ['uid' => 7];
$r = manualreg_SaveNameservers(params(1, ['ns1.a.com', 'ns2.a.com']));
check('save returns generic error', isset($r['error']) && !isset($r['success']));
check('error does not leak internals', stripos($r['error'], 'activated') === false);
check('get returns generic error', isset(manualreg_GetNameservers(params(1))['error']));

nsmanager_activate();

echo "GetNameservers\n";
$g = manualreg_GetNameservers(params(1));
check('empty when nothing stored', $g === ['ns1' => '', 'ns2' => '', 'ns3' => '', 'ns4' => '', 'ns5' => '']);

echo "SaveNameservers as client\n";
$_SESSION = ['uid' => 7];
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$r = manualreg_SaveNameservers(params(1, ['NS1.Host.com', 'ns2.host.com']));
check('save succeeds', $r === ['success' => true]);
$req = Repository::findPendingByDomain(1);
check('pending row created', $req !== null && $req->status === 'pending');
check('actor is client:7', $req->requested_by === 'client:7');
check('ip recorded', $req->requester_ip === '203.0.113.5');
check('ns normalized', Repository::decodeNs($req->new_ns) === ['ns1.host.com', 'ns2.host.com']);
$g = manualreg_GetNameservers(params(1));
check('get shows pending ns', $g['ns1'] === 'ns1.host.com' && $g['ns2'] === 'ns2.host.com' && $g['ns3'] === '');
check('audit row written', count(Repository::getLog(1)) === 1);

echo "validation errors reach the customer\n";
$r = manualreg_SaveNameservers(params(1, ['ns1.host.com']));
check('too few ns -> error', isset($r['error']) && strpos($r['error'], 'At least 2') === 0);
$r = manualreg_SaveNameservers(params(1, ['ns1.host.com', '1.2.3.4']));
check('ip -> error', isset($r['error']));
check('failed saves left pending untouched', Repository::findPendingByDomain(1)->id == $req->id);

echo "ownership and actor\n";
$_SESSION = ['uid' => 7];
$r = manualreg_SaveNameservers(params(2, ['ns1.x.com', 'ns2.x.com']));
check("client cannot change another client's domain", isset($r['error']) && Repository::findPendingByDomain(2) === null);

$_SESSION = ['adminid' => 3];
$r = manualreg_SaveNameservers(params(2, ['ns1.x.com', 'ns2.x.com']));
check('admin can change any domain', $r === ['success' => true]);
check('actor is admin:3', Repository::findPendingByDomain(2)->requested_by === 'admin:3');

$_SESSION = [];
$r = manualreg_SaveNameservers(params(1, ['ns1.sys.com', 'ns2.sys.com']));
check('no session -> system actor', $r === ['success' => true] && Repository::findPendingByDomain(1)->requested_by === 'system');

echo "supersede and applied\n";
check('newer save supersedes old', Repository::findRequest($req->id)->status === 'superseded');
NsManager\RequestService::markApplied((int) Repository::findPendingByDomain(1)->id, 'admin:1');
$g = manualreg_GetNameservers(params(1));
check('get shows applied ns', $g['ns1'] === 'ns1.sys.com');
$_SESSION = ['uid' => 7];
check('saving identical ns is a silent success', manualreg_SaveNameservers(params(1, ['ns1.sys.com', 'ns2.sys.com'])) === ['success' => true] && Repository::findPendingByDomain(1) === null);

finish();
