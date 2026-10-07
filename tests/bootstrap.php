<?php
/**
 * Dev-only harness shared by the tests: illuminate/database + in-memory SQLite,
 * aliased as WHMCS\Database\Capsule, plus a fake tbldomains.
 */

require __DIR__ . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as IlluminateCapsule;

define('WHMCS', true);
define('ROOTDIR', dirname(__DIR__));

$capsule = new IlluminateCapsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
class_alias(IlluminateCapsule::class, 'WHMCS\Database\Capsule');

// WHMCS owns tbldomains/tblclients; fake the columns we read.
WHMCS\Database\Capsule::schema()->create('tbldomains', function ($t) {
    $t->increments('id');
    $t->unsignedInteger('userid');
    $t->string('domain');
    $t->string('registrar')->nullable();
    $t->string('status')->nullable();
});
WHMCS\Database\Capsule::schema()->create('tblclients', function ($t) {
    $t->increments('id');
    $t->string('firstname')->nullable();
    $t->string('lastname')->nullable();
    $t->string('companyname')->nullable();
});

WHMCS\Database\Capsule::schema()->create('tbladdonmodules', function ($t) {
    $t->increments('id');
    $t->string('module');
    $t->string('setting');
    $t->text('value')->nullable();
});
// Most tests create many requests for one domain, so start with the rate limit off.
WHMCS\Database\Capsule::table('tbladdonmodules')->insert(['module' => 'nsmanager', 'setting' => 'rate_limit_per_hour', 'value' => '0']);
WHMCS\Database\Capsule::schema()->create('tblconfiguration', function ($t) {
    $t->increments('id');
    $t->string('setting');
    $t->text('value')->nullable();
});
WHMCS\Database\Capsule::table('tblconfiguration')->insert([
    ['setting' => 'SystemURL', 'value' => 'https://billing.example.com/'],
    ['setting' => 'SystemEmailsFromEmail', 'value' => 'noreply@example.com'],
]);

// WHMCS global functions the addon calls. Calls are recorded for assertions;
// set $GLOBALS['__api_result'] to simulate a failing local API.
$GLOBALS['__api_calls'] = [];
$GLOBALS['__api_result'] = ['result' => 'success'];
$GLOBALS['__activity'] = [];
function localAPI($command, $values = [])
{
    $GLOBALS['__api_calls'][] = [$command, $values];
    return $GLOBALS['__api_result'];
}
function logActivity($message)
{
    $GLOBALS['__activity'][] = $message;
}
// Stand-in for WHMCS decrypt(): only strings prefixed "enc:" decrypt.
function decrypt($value)
{
    return strpos($value, 'enc:') === 0 ? substr($value, 4) : '';
}

$failures = 0;
function check(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? '  ok   ' : '  FAIL ') . $name . PHP_EOL;
    if (!$cond) {
        $failures++;
    }
}

function finish(): void
{
    global $failures;
    echo PHP_EOL . ($failures === 0 ? 'ALL PASSED' : "$failures FAILED") . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}
