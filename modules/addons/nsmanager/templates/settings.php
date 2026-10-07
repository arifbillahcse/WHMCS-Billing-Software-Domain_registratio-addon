<?php
/** @var callable $e @var callable $url @var string $token @var array $status @var array|null $results */
?>
<p>Channels are configured under <strong>System Settings &rarr; Addon Modules &rarr; Nameserver Manager &rarr; Configure</strong>.
    Admin alerts are sent when a customer or the system queues a request; requests you create yourself are not announced.</p>

<table class="table table-striped table-condensed" style="max-width:640px">
    <thead><tr><th>Channel</th><th>Enabled</th><th>Configured</th><?php if ($results !== null): ?><th>Test result</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($status as $name => $s): ?>
        <tr>
            <td><?= $e($name) ?></td>
            <td><span class="label label-<?= $s['enabled'] ? 'success' : 'default' ?>"><?= $s['enabled'] ? 'on' : 'off' ?></span></td>
            <td><span class="label label-<?= $s['configured'] ? 'success' : 'warning' ?>"><?= $s['configured'] ? 'yes' : 'incomplete' ?></span></td>
            <?php if ($results !== null): ?>
                <td>
                    <?php if (isset($results[$name])): ?>
                        <span class="label label-<?= $results[$name]['ok'] ? 'success' : 'danger' ?>"><?= $results[$name]['ok'] ? 'ok' : 'failed' ?></span>
                        <small><?= $e($results[$name]['message']) ?></small>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<form method="post" action="<?= $e($url(['tab' => 'settings'])) ?>">
    <input type="hidden" name="token" value="<?= $e($token) ?>">
    <button name="test_notify" value="1" class="btn btn-primary">Send test notification</button>
    <span class="muted">Sends a test message through every enabled channel.</span>
</form>
