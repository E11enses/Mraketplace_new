<?php if (!empty($pager) && $pager['pages'] > 1): ?>
<div class="row">
  <div class="column large-full">
    <nav class="pgn"><ul>
      <?php if ($pager['page'] > 1): ?>
        <li><a class="pgn__prev" href="?<?= http_build_query(array_merge($_GET, ['page'=>$pager['page']-1])) ?>">Предыдущие</a></li>
      <?php endif; ?>
      <?php for ($i=1;$i<=$pager['pages'];$i++): ?>
        <?php if ($i == $pager['page']): ?>
          <li><span class="pgn__num current"><?= $i ?></span></li>
        <?php else: ?>
          <li><a class="pgn__num" href="?<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>"><?= $i ?></a></li>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($pager['page'] < $pager['pages']): ?>
        <li><a class="pgn__next" href="?<?= http_build_query(array_merge($_GET, ['page'=>$pager['page']+1])) ?>">Следующие</a></li>
      <?php endif; ?>
    </ul></nav>
  </div>
</div>
<?php endif; ?>