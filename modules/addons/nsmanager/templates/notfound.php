<?php /** @var callable $url @var callable $e */ ?>
<div class="alert alert-danger">Domain not found.</div>
<p><a href="<?= $e($url(['tab' => 'pending'])) ?>">&laquo; Back to the queue</a></p>
