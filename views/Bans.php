<?php
use Foolz\FoolFuuka\Model\CommentBulk;
use Foolz\FoolFuuka\Model\Media;
?>
<div id="table">
<center>
<article>
<table class="bansListing">
<tbody>
<tr>
<th class="col-post">Post #</th>
<th class="col-action">Type</th>
<th class="postblock">Reason</th>
<th class="postblock">Length</th>
<th class="col-time">Time</th>
</tr>
<?php
foreach ($result as $k) :
?>
<tr>
<td class="no">
<a href="<?= $this->uri->create($radix->shortname . '/post/' . $k['no']) ?>" class="backlink" data-function="highlight" data-backlink="true" data-post="<?= $k['no'] ?>" data-board="<?= $radix->shortname ?>">No. <?= $k['no'] ?></a>
</td>
<td class="type">
<?php if ($k['type'] == 1) : ?>
    <span>Ban</span>
<?php else: ?>
    <span>Warn</span>
<?php endif; ?>
</td>
<td class="reason">
    <?= htmlspecialchars(strip_tags($k['reason'])) ?>
</td>
<td class="length">
    <?= htmlspecialchars(strip_tags($k['banlength'])) ?>
</td>
<td class="date">
<time datetime="<?= gmdate(DATE_W3C, $k['bantime']) ?>" class="show_time"><?= gmdate('D d M H:i:s Y', $k['bantime']) ?></time>
</td>
</tr>
<?php
endforeach;
?>
</tbody>
</table>
</article>
</center>
</div>
<article class="clearfix thread backlink_container">
<div id="backlink" style="position: absolute; top: 0; left: 0; z-index: 5;"></div>
</article>
