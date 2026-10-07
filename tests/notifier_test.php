<?php
/**
 * Dev-only test for notifications (Settings + Notifier + RequestService hooks).
 *
 *   cd tests && composer install && php notifier_test.php
 */

require __DIR__ . '/bootstrap.php';
require ROOTDIR . '/modules/addons/nsmanager/nsmanager.php';

use NsManager\AdminController as Admin;
use NsManager\Notifier;
use NsManager\Repository;
use NsManager\RequestService as RS;
use NsManager\Settings;
use WHMCS\Database\Capsule;

nsmanager_activate();
Capsule::table('tblclients')->insert(['id' => 7, 'firstname' => 'Ada', 'lastname' => 'Lovelace', 'companyname' => '']);
Capsule::table('tbldomains')->insert([
    ['id' => 1, 'userid' => 7, 'domain' => 'example.com', 'registrar' => 'manualreg', 'status' => 'Active'],
    ['id' => 2, 'userid' => 7, 'domain' => 'second.org', 'registrar' => 'manualreg', 'status' => 'Active'],
]);

function setting(string $key, string $value): void
{
    Capsule::table('tbladdonmodules')->where(['module' => 'nsmanager', 'setting' => $key])->delete();
    Capsule::table('tbladdonmodules')->insert(['module' => 'nsmanager', 'setting' => $key, 'value' => $value]);
    Settings::reset();
}

function resetCalls(): void
{
    $GLOBALS['__api_calls'] = [];
    $GLOBALS['__api_result'] = ['result' => 'success'];
    $GLOBALS['__http'] = [];
    Notifier::setHttpClient(function ($url, $payload) {
        $GLOBALS['__http'][] = [$url, $payload];
    });
}

function calls(string $command): array
{
    return array_values(array_filter($GLOBALS['__api_calls'], fn ($c) => $c[0] === $command));
}

function failures(): array
{
    return array_map(fn ($l) => $l->details, array_values(array_filter(Repository::getLog(), fn ($l) => $l->event === 'notify_failed')));
}

$validToken = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';
$validHook = 'https://hooks.slack.com/services/T000/B000/XXXXXXXX';

echo "defaults: only admin email is on\n";
resetCalls();
$r = RS::createRequest(1, ['NS1.Host.com', 'ns2.host.com'], 'client:7', '203.0.113.9');
$mail = calls('SendAdminEmail');
check('one admin email sent', count($mail) === 1 && $r['success']);
check('system email type + subject', $mail[0][1]['type'] === 'system' && strpos($mail[0][1]['customsubject'], 'example.com') !== false);
$msg = $mail[0][1]['custommessage'];
check('message has customer, old/new ns, requester', strpos($msg, 'Ada Lovelace') !== false && strpos($msg, 'ns1.host.com, ns2.host.com') !== false && strpos($msg, '203.0.113.9') !== false && strpos($msg, 'unknown') !== false);
check('message links to admin page', strpos($msg, 'https://billing.example.com/admin/addonmodules.php?module=nsmanager') !== false);
check('no other channel fired', calls('OpenTicket') === [] && $GLOBALS['__http'] === []);
check('no failures logged', failures() === []);

echo "who triggers an alert\n";
resetCalls();
RS::createRequest(1, ['ns1.host.com', 'ns2.host.com'], 'client:7');
check('noop request -> no alert', $GLOBALS['__api_calls'] === []);
RS::createRequest(1, ['ns1.admin.com', 'ns2.admin.com'], 'admin:1');
check('admin-made request -> no alert', $GLOBALS['__api_calls'] === []);
RS::createRequest(1, ['ns1.sys.com', 'ns2.sys.com'], 'system');
check('system request -> alert', count(calls('SendAdminEmail')) === 1);
resetCalls();
RS::createRequest(1, ['ns1.newer.com', 'ns2.newer.com'], 'client:7');
check('superseding request mentions the old one', preg_match('/Replaces earlier pending request #\d+/', calls('SendAdminEmail')[0][1]['custommessage']) === 1);

echo "notifications can be switched off\n";
resetCalls();
RS::$notify = false;
RS::createRequest(2, ['ns1.quiet.com', 'ns2.quiet.com'], 'client:7');
check('RequestService::$notify=false is silent', $GLOBALS['__api_calls'] === []);
RS::$notify = true;
setting('notify_admin_email', '');
resetCalls();
RS::createRequest(2, ['ns1.off.com', 'ns2.off.com'], 'client:7');
check('admin email setting off -> silent', $GLOBALS['__api_calls'] === []);
setting('notify_admin_email', 'on');

echo "support ticket channel\n";
setting('notify_ticket', 'on');
resetCalls();
RS::createRequest(2, ['ns1.t1.com', 'ns2.t1.com'], 'client:7');
check('enabled but no department -> failure logged, email still sent', count(calls('SendAdminEmail')) === 1 && calls('OpenTicket') === [] && strpos(implode('|', failures()), 'Support ticket: Ticket department') !== false);
setting('ticket_dept_id', '5');
setting('ticket_priority', 'High');
resetCalls();
RS::createRequest(2, ['ns1.t2.com', 'ns2.t2.com'], 'client:7');
$t = calls('OpenTicket');
check('ticket opened', count($t) === 1);
check('ticket params', $t[0][1]['deptid'] === 5 && $t[0][1]['priority'] === 'High' && $t[0][1]['email'] === 'noreply@example.com' && $t[0][1]['noemail'] === true && !isset($t[0][1]['clientid']));
check('ticket body has details', strpos($t[0][1]['message'], 'ns1.t2.com') !== false && strpos($t[0][1]['subject'], 'second.org') !== false);
setting('notify_ticket', '');

echo "telegram channel\n";
setting('notify_telegram', 'on');
setting('telegram_chat_id', '-100123');
resetCalls();
RS::createRequest(2, ['ns1.tg0.com', 'ns2.tg0.com'], 'client:7');
check('no token -> not configured, logged', $GLOBALS['__http'] === [] && strpos(implode('|', failures()), 'Telegram: Telegram bot token') !== false);
setting('telegram_bot_token', 'enc:' . $validToken);
resetCalls();
RS::createRequest(2, ['ns1.tg1.com', 'ns2.tg1.com'], 'client:7');
check('encrypted token decrypted, message posted', count($GLOBALS['__http']) === 1 && $GLOBALS['__http'][0][0] === "https://api.telegram.org/bot$validToken/sendMessage");
check('telegram payload', $GLOBALS['__http'][0][1]['chat_id'] === '-100123' && strpos($GLOBALS['__http'][0][1]['text'], 'ns1.tg1.com') !== false);
setting('telegram_bot_token', $validToken);
resetCalls();
RS::createRequest(2, ['ns1.tg2.com', 'ns2.tg2.com'], 'client:7');
check('plain-text token also works', count($GLOBALS['__http']) === 1);
setting('telegram_bot_token', 'enc:not-a-token');
resetCalls();
RS::createRequest(2, ['ns1.tg3.com', 'ns2.tg3.com'], 'client:7');
check('malformed token rejected', $GLOBALS['__http'] === []);

setting('telegram_bot_token', 'enc:' . $validToken);
Notifier::setHttpClient(function ($url, $payload) use ($validToken) {
    throw new RuntimeException("cURL error for $url with $validToken");
});
$before = count(failures());
$r = RS::createRequest(2, ['ns1.tg4.com', 'ns2.tg4.com'], 'client:7');
check('http failure never breaks the request', $r['success'] && Repository::findRequest($r['request_id'])->status === 'pending');
check('http failure logged', count(failures()) === $before + 1);
check('bot token scrubbed from logged error', strpos(implode('|', failures()), $validToken) === false && strpos(implode('|', failures()), '***') !== false);
check('token scrubbed from activity log too', strpos(implode('|', $GLOBALS['__activity']), $validToken) === false);
setting('notify_telegram', '');

echo "slack channel\n";
setting('notify_slack', 'on');
setting('slack_webhook_url', 'enc:https://evil.example.com/hook');
resetCalls();
RS::createRequest(2, ['ns1.s0.com', 'ns2.s0.com'], 'client:7');
check('non-slack webhook refused', $GLOBALS['__http'] === []);
setting('slack_webhook_url', 'enc:' . $validHook);
resetCalls();
RS::createRequest(2, ['ns1.s1.com', 'ns2.s1.com'], 'client:7');
check('slack posted', count($GLOBALS['__http']) === 1 && $GLOBALS['__http'][0][0] === $validHook && strpos($GLOBALS['__http'][0][1]['text'], 'ns1.s1.com') !== false);

echo "a failing channel does not block the others\n";
$GLOBALS['__api_result'] = ['result' => 'error', 'message' => 'Invalid admin'];
$GLOBALS['__http'] = [];
Notifier::setHttpClient(function ($url, $payload) {
    $GLOBALS['__http'][] = [$url, $payload];
});
$r = RS::createRequest(2, ['ns1.mix.com', 'ns2.mix.com'], 'client:7');
check('slack still sent after email failed', count($GLOBALS['__http']) === 1 && $r['success']);
check('email failure logged with reason', strpos(implode('|', failures()), 'Admin email: SendAdminEmail failed: Invalid admin') !== false);
setting('notify_slack', '');

echo "customer emails\n";
resetCalls();
$req = RS::createRequest(1, ['ns1.cust.com', 'ns2.cust.com'], 'client:7');
resetCalls();
RS::markApplied($req['request_id'], 'admin:1', 'internal note {$secret}');
$mail = calls('SendEmail');
check('customer email on applied', count($mail) === 1 && $mail[0][1]['customtype'] === 'general' && $mail[0][1]['id'] === 7);
check('subject names the domain', strpos($mail[0][1]['customsubject'], 'completed for example.com') !== false);
check('body has merge field + new ns', substr_count($mail[0][1]['custommessage'], '{$client_name}') === 1 && strpos($mail[0][1]['custommessage'], '<li>ns1.cust.com</li>') !== false);
check('internal note not sent to customer', strpos($mail[0][1]['custommessage'], 'internal note') === false);

$req = RS::createRequest(1, ['ns1.cust2.com', 'ns2.cust2.com'], 'client:7');
resetCalls();
RS::markFailed($req['request_id'], 'admin:1', 'Domain locked <b>{$smarty.version}</b> {php}x{/php}');
$mail = calls('SendEmail');
$body = $mail[0][1]['custommessage'];
check('customer email on failed', count($mail) === 1 && strpos($mail[0][1]['customsubject'], 'could not be completed') !== false);
check('reason included, HTML escaped', strpos($body, 'Domain locked &lt;b&gt;') !== false && strpos($body, '<b>') === false);
check('no smarty braces from admin text', substr_count($body, '{') === 1 && substr_count($body, '}') === 1);

setting('notify_customer_applied', '');
setting('notify_customer_failed', '');
$req = RS::createRequest(1, ['ns1.cust3.com', 'ns2.cust3.com'], 'client:7');
resetCalls();
RS::markApplied($req['request_id'], 'admin:1');
$req = RS::createRequest(1, ['ns1.cust4.com', 'ns2.cust4.com'], 'client:7');
RS::markFailed($req['request_id'], 'admin:1', 'nope');
check('customer emails can be disabled', calls('SendEmail') === []);
setting('notify_customer_applied', 'on');
setting('notify_customer_failed', 'on');

$req = RS::createRequest(1, ['ns1.cust5.com', 'ns2.cust5.com'], 'client:7');
RS::cancel($req['request_id'], 'client:7');
resetCalls();
$req = RS::createRequest(1, ['ns1.cust6.com', 'ns2.cust6.com'], 'client:7');
resetCalls();
$GLOBALS['__api_result'] = ['result' => 'error', 'message' => 'Client not found'];
$res = RS::markApplied($req['request_id'], 'admin:1');
check('customer email failure does not undo the apply', $res['success'] && Repository::findRequest($req['request_id'])->status === 'applied');
check('customer email failure logged', strpos(implode('|', failures()), 'Customer email: SendEmail failed: Client not found') !== false);

echo "sanitizer\n";
check('clean strips braces and control chars', Notifier::clean("a{b}c\r\x07d\ne") === "abcd\ne");
check('clean truncates', mb_strlen(Notifier::clean(str_repeat('x', 50), 10)) === 10);

echo "test button and status\n";
resetCalls();
setting('notify_telegram', 'on');
setting('notify_slack', '');
setting('notify_ticket', '');
$res = Notifier::sendTest();
check('test covers every channel', array_keys($res) === ['Admin email', 'Support ticket', 'Telegram', 'Slack']);
check('enabled channels ok, disabled reported', $res['Admin email']['ok'] && $res['Telegram']['ok'] && !$res['Slack']['ok'] && $res['Slack']['message'] === 'Disabled in addon settings.');
$st = Notifier::status();
check('status flags', $st['Telegram'] === ['enabled' => true, 'configured' => true] && $st['Slack']['enabled'] === false && $st['Support ticket']['configured'] === true);

function generate_token($format = 'form')
{
    return 'TESTTOKEN';
}
$vars = ['modulelink' => 'addonmodules.php?module=nsmanager'];
$html = Admin::handle($vars, ['tab' => 'settings'], [], 'admin:1', false);
check('settings tab renders with test button', strpos($html, 'Send test notification') !== false && strpos($html, 'Notifications') !== false && strpos($html, 'Test result') === false);
resetCalls();
$html = Admin::handle($vars, ['tab' => 'settings'], ['test_notify' => '1'], 'admin:1', true);
check('test button runs the test and shows results', strpos($html, 'Test result') !== false && count(calls('SendAdminEmail')) === 1 && strpos($html, 'Disabled in addon settings.') !== false);

finish();
