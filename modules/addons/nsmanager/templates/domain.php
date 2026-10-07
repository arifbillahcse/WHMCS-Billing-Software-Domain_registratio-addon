<?php
/**
 * @var callable $e @var callable $url @var string $token @var object $domain @var string $client
 * @var object|null $meta @var array $applied @var string $link @var array $requests @var array $log
 */
use NsManager\Repository;
use NsManager\View;
?>
<p><a href="<?= $e($url(['tab' => 'pending'])) ?>">&laquo; Back to the queue</a></p>
<h3 style="margin-top:0"><?= $e($domain->domain) ?>
    <small><a href="clientssummary.php?userid=<?= (int) $domain->userid ?>"><?= $e($client) ?></a></small>
    <small><a href="clientsdomains.php?id=<?= (int) $domain->id ?>">WHMCS domain page</a></small>
</h3>

<div class="panel panel-default">
    <div class="panel-heading"><strong>Provider details</strong>
        <?php if ($link): ?>
            &nbsp;<a href="<?= $e($link) ?>" target="_blank" rel="noopener noreferrer">Open provider login &#8599;</a>
        <?php endif; ?>
    </div>
    <div class="panel-body">
        <form method="post" action="<?= $e($url(['action' => 'domain', 'id' => $domain->id])) ?>">
            <input type="hidden" name="token" value="<?= $e($token) ?>">
            <input type="hidden" name="domain_id" value="<?= (int) $domain->id ?>">
            <div class="row">
                <div class="col-sm-4 form-group">
                    <label>Provider</label>
                    <input class="form-control" name="provider" maxlength="100" placeholder="Namecheap, GoDaddy..." value="<?= $e($meta->provider ?? '') ?>">
                </div>
                <div class="col-sm-4 form-group">
                    <label>Provider login URL</label>
                    <input class="form-control" name="provider_login_url" maxlength="255" placeholder="https://..." value="<?= $e($meta->provider_login_url ?? '') ?>">
                </div>
                <div class="col-sm-4 form-group">
                    <label>Account label</label>
                    <input class="form-control" name="account_label" maxlength="100" placeholder="Which login holds this domain" value="<?= $e($meta->account_label ?? '') ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-sm-6 form-group">
                    <label>Nameservers live at the provider</label>
                    <textarea class="form-control" name="applied_ns" rows="4" placeholder="One per line"><?= $e(implode("\n", $applied)) ?></textarea>
                    <p class="help-block">Shown to the customer when no request is open, and used as the "old" value for new requests.</p>
                </div>
                <div class="col-sm-6 form-group">
                    <label>Internal notes</label>
                    <textarea class="form-control" name="notes" rows="4"><?= $e($meta->notes ?? '') ?></textarea>
                </div>
            </div>
            <button name="save_domain" value="1" class="btn btn-primary">Save</button>
        </form>
    </div>
</div>

<h4>Requests</h4>
<?php if (!$requests): ?>
    <p class="muted">No requests for this domain.</p>
<?php else: ?>
<table class="table table-striped table-condensed">
    <thead><tr><th>#</th><th>Status</th><th>Old</th><th>New</th><th>Requested</th><th>Resolution</th></tr></thead>
    <tbody>
    <?php foreach ($requests as $r): ?>
        <tr>
            <td><?= (int) $r->id ?></td>
            <td><span class="label label-<?= ['applied' => 'success', 'failed' => 'danger', 'pending' => 'warning'][$r->status] ?? 'default' ?>"><?= $e($r->status) ?></span></td>
            <td><?php foreach (Repository::decodeNs($r->old_ns) as $ns): ?><code><?= $e($ns) ?></code><br><?php endforeach; ?></td>
            <td><?php foreach (Repository::decodeNs($r->new_ns) as $ns): ?><code><?= $e($ns) ?></code><br><?php endforeach; ?></td>
            <td><?= $e($r->created_at) ?><br><small class="muted"><?= $e($r->requested_by) ?></small></td>
            <td>
                <?php if ($r->resolved_at): ?><?= $e($r->resolved_at) ?><br><small class="muted"><?= $e($r->resolved_by) ?></small><?php endif; ?>
                <?php if ($r->admin_note): ?><br><small><?= $e($r->admin_note) ?></small><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h4>Audit history</h4>
<?php if (!$log): ?>
    <p class="muted">Nothing logged yet.</p>
<?php else: ?>
<table class="table table-striped table-condensed">
    <thead><tr><th>When</th><th>Event</th><th>By</th><th>Request</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($log as $l): ?>
        <tr>
            <td><?= $e($l->created_at) ?></td>
            <td><?= $e($l->event) ?></td>
            <td><?= $e($l->actor) ?></td>
            <td><?= $l->request_id ? '#' . (int) $l->request_id : '' ?></td>
            <td><small style="word-break:break-all"><?= $e($l->details) ?></small></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
