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
