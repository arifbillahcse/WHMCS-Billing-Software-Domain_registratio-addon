<?php
/** @var callable $e @var callable $url @var string $tab @var array $counts @var array $flash @var string $body */
$tabs = [
    'pending' => 'Pending',
    'applied' => 'Applied',
    'failed' => 'Failed',
    'all' => 'All',
    'providers' => 'Providers',
    'settings' => 'Notifications',
];
$pendingCount = (int) ($counts['pending'] ?? 0);
?>
<style>
.nsm code { white-space: nowrap; }
.nsm .ns-new { color: #1a7f37; font-weight: 600; }
.nsm .ns-gone { color: #888; text-decoration: line-through; }
.nsm .ns-block { margin-bottom: 4px; line-height: 1.6; }
.nsm .stale { color: #c0392b; font-weight: 600; }
.nsm td { vertical-align: middle !important; }
.nsm .muted { color: #888; }
.nsm .nsm-actions button { margin: 1px 0; }
.nsm .badge-count { background: #e67e22; color: #fff; border-radius: 10px; padding: 1px 7px; font-size: 11px; margin-left: 4px; }
</style>
<div class="nsm">
    <ul class="nav nav-tabs" style="margin-bottom:15px">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="<?= $tab === $key ? 'active' : '' ?>">
                <a href="<?= $e($url(['tab' => $key])) ?>">
                    <?= $e($label) ?>
                    <?php if ($key === 'pending' && $pendingCount > 0): ?>
                        <span class="badge-count"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php foreach ($flash as $message): ?>
        <div class="alert alert-<?= $e($message['type']) ?>"><?= $e($message['text']) ?></div>
    <?php endforeach; ?>

    <?= $body ?>
</div>
