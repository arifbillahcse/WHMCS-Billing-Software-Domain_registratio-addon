<?php
/** @var callable $e @var callable $url @var int $page @var int $pages @var int $total @var array $query */
if ($pages > 1): ?>
    <p>
        <?php if ($page > 1): ?>
            <a href="<?= $e($url($query + ['page' => $page - 1])) ?>">&laquo; Previous</a> &nbsp;
        <?php endif; ?>
        Page <?= (int) $page ?> of <?= (int) $pages ?> (<?= (int) $total ?> total)
        <?php if ($page < $pages): ?>
            &nbsp; <a href="<?= $e($url($query + ['page' => $page + 1])) ?>">Next &raquo;</a>
        <?php endif; ?>
    </p>
<?php endif; ?>
