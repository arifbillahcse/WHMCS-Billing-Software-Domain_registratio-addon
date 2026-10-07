<?php
/**
 * @var callable $e @var callable $url @var string $token @var string $tab
 * @var array $items @var int $page @var int $pages @var int $total
 */
use NsManager\View;

$isPending = $tab === 'pending';
?>
<?php if (!$items): ?>
    <p class="muted"><?= $isPending ? 'No pending requests. Nice work.' : 'No requests here yet.' ?></p>
<?php else: ?>
<form method="post" action="<?= $e($url(['tab' => $tab, 'page' => $page])) ?>">
    <input type="hidden" name="token" value="<?= $e($token) ?>">
    <input type="hidden" name="reason" value="">
    <?php // A disabled default button stops Enter on a checkbox from firing the first row's action. ?>
    <button type="submit" disabled hidden aria-hidden="true"></button>

    <table class="table table-striped table-condensed">
        <thead>
            <tr>
                <?php if ($isPending): ?><th style="width:24px"><input type="checkbox" onclick="document.querySelectorAll('.nsm-pick').forEach(function (c) { c.checked = this.checked; }, this)"></th><?php endif; ?>
                <th>#</th>
                <th>Domain</th>
                <th>Customer</th>
                <th>Provider</th>
                <th>Nameservers</th>
                <th>Requested</th>
                <th><?= $isPending ? 'Age' : 'Status' ?></th>
                <th><?= $isPending ? 'Actions' : 'Resolution' ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): $r = $item['row']; ?>
            <tr>
                <?php if ($isPending): ?><td><input type="checkbox" class="nsm-pick" name="ids[]" value="<?= (int) $r->id ?>"></td><?php endif; ?>
                <td><?= (int) $r->id ?></td>
                <td><a href="<?= $e($url(['action' => 'domain', 'id' => $r->domain_id])) ?>"><strong><?= $e($r->domain) ?></strong></a></td>
                <td><a href="clientssummary.php?userid=<?= (int) $r->client_id ?>"><?= $e($item['client'] ?: ('#' . $r->client_id)) ?></a></td>
                <td>
                    <?php if ($r->provider): ?>
                        <?php if ($item['link']): ?>
                            <a href="<?= $e($item['link']) ?>" target="_blank" rel="noopener noreferrer"><?= $e($r->provider) ?> &#8599;</a>
                        <?php else: ?>
                            <?= $e($r->provider) ?>
                        <?php endif; ?>
                        <?php if ($r->account_label): ?><br><small class="muted"><?= $e($r->account_label) ?></small><?php endif; ?>
                    <?php else: ?>
                        <a href="<?= $e($url(['action' => 'domain', 'id' => $r->domain_id])) ?>" class="muted">set provider</a>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($item['old']): ?>
                        <div class="ns-block muted">
                            <?php foreach ($item['old'] as $ns): ?>
                                <code class="<?= in_array($ns, $item['new'], true) ? '' : 'ns-gone' ?>"><?= $e($ns) ?></code><br>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="ns-block">
                        <?php foreach ($item['new'] as $ns): ?>
                            <code class="<?= in_array($ns, $item['old'], true) ? '' : 'ns-new' ?>"><?= $e($ns) ?></code><br>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-default btn-xs" data-copy="<?= $e(implode("\n", $item['new'])) ?>"
                            onclick="navigator.clipboard.writeText(this.dataset.copy); this.textContent = 'Copied'">Copy</button>
                </td>
                <td>
                    <?= $e($r->created_at) ?><br>
                    <small class="muted"><?= $e($r->requested_by) ?><?= $r->requester_ip ? ' &middot; ' . $e($r->requester_ip) : '' ?></small>
                </td>
                <?php if ($isPending): ?>
                    <td class="<?= View::isStale($r->created_at) ? 'stale' : '' ?>"><?= $e(View::ageText($r->created_at)) ?></td>
                    <td class="nsm-actions" style="white-space:nowrap">
                        <button name="row_action" value="apply:<?= (int) $r->id ?>" class="btn btn-success btn-xs">Mark applied</button>
                        <button name="row_action" value="fail:<?= (int) $r->id ?>" class="btn btn-danger btn-xs"
                                onclick="var r = prompt('Why did this fail? (shown to the customer)'); if (!r) { return false; } this.form.reason.value = r;">Failed</button>
                        <button name="row_action" value="cancel:<?= (int) $r->id ?>" class="btn btn-default btn-xs"
                                onclick="return confirm('Cancel this request?');">Cancel</button>
                    </td>
                <?php else: ?>
                    <td><span class="label label-<?= ['applied' => 'success', 'failed' => 'danger', 'pending' => 'warning'][$r->status] ?? 'default' ?>"><?= $e($r->status) ?></span></td>
                    <td>
                        <?php if ($r->resolved_at): ?>
                            <?= $e($r->resolved_at) ?><br><small class="muted"><?= $e($r->resolved_by) ?></small>
                        <?php endif; ?>
                        <?php if ($r->admin_note): ?><br><small><?= $e($r->admin_note) ?></small><?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($isPending): ?>
        <button name="bulk" value="apply" class="btn btn-success"
                onclick="return confirm('Mark all selected requests as applied?');">Mark selected as applied</button>
    <?php endif; ?>
</form>
<?php endif; ?>

<?= \NsManager\View::render('pager', ['e' => $e, 'url' => $url, 'page' => $page, 'pages' => $pages, 'total' => $total, 'query' => ['tab' => $tab]]) ?>
