<?php
/**
 * @var callable $e @var callable $url @var array $items @var string $search
 * @var int $page @var int $pages @var int $total
 */
?>
<form method="get" action="addonmodules.php" class="form-inline" style="margin-bottom:12px">
    <input type="hidden" name="module" value="nsmanager">
    <input type="hidden" name="tab" value="providers">
    <input type="text" name="q" value="<?= $e($search) ?>" class="form-control" placeholder="Search domain or provider">
    <button class="btn btn-default">Search</button>
</form>

<?php if (!$items): ?>
    <p class="muted">No domains use the Manual Registrar<?= $search !== '' ? ' matching that search' : ' yet' ?>.</p>
<?php else: ?>
<table class="table table-striped table-condensed">
    <thead>
        <tr><th>Domain</th><th>Customer</th><th>Provider</th><th>Account</th><th>Live nameservers</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item): $r = $item['row']; ?>
        <tr>
            <td><strong><?= $e($r->domain) ?></strong>
                <?php if ((int) $r->pending_count > 0): ?><span class="label label-warning">pending</span><?php endif; ?>
            </td>
            <td><a href="clientssummary.php?userid=<?= (int) $r->userid ?>"><?= $e($item['client'] ?: ('#' . $r->userid)) ?></a></td>
            <td>
                <?php if ($r->provider): ?>
                    <?php if ($item['link']): ?>
                        <a href="<?= $e($item['link']) ?>" target="_blank" rel="noopener noreferrer"><?= $e($r->provider) ?> &#8599;</a>
                    <?php else: ?><?= $e($r->provider) ?><?php endif; ?>
                <?php else: ?><span class="muted">not set</span><?php endif; ?>
            </td>
            <td><?= $e($r->account_label) ?></td>
            <td>
                <?php foreach ($item['applied'] as $ns): ?><code><?= $e($ns) ?></code><br><?php endforeach; ?>
                <?php if (!$item['applied']): ?><span class="muted">unknown</span><?php endif; ?>
            </td>
            <td><a class="btn btn-default btn-xs" href="<?= $e($url(['action' => 'domain', 'id' => $r->domain_id])) ?>">Edit</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?= \NsManager\View::render('pager', ['e' => $e, 'url' => $url, 'page' => $page, 'pages' => $pages, 'total' => $total, 'query' => ['tab' => 'providers', 'q' => $search]]) ?>
