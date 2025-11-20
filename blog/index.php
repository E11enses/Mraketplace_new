<?php
$config = require __DIR__.'/config.php';
require __DIR__.'/inc/functions.php';
$all = load_posts($config['posts_dir']);

$cat = $_GET['category'] ?? null;
$tag = $_GET['tag'] ?? null;
if ($cat) $all = array_values(array_filter($all, fn($p)=>in_array($cat, $p['meta']['categories'], true)));
if ($tag) $all = array_values(array_filter($all, fn($p)=>in_array($tag, $p['meta']['tags'], true)));

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pager = paginate($all, $page, $config['per_page']);

$config['site_name'] = 'Typerite';
include __DIR__.'/inc/header.php';
?>
<div class="s-content">
  <div class="masonry-wrap">
    <div class="masonry">
      <div class="grid-sizer"></div>
      <?php foreach ($pager['items'] as $p): $m=$p['meta']; ?>
      <article class="masonry__brick entry format-standard animate-this">
        <?php if (!empty($m['thumb'])): ?>
        <div class="entry__thumb">
          <a href="<?= e($m['url']) ?>" class="entry__thumb-link">
            <img src="<?= e($m['thumb']) ?>" alt="">
          </a>
        </div>
        <?php endif; ?>
        <div class="entry__text">
          <div class="entry__header">
            <h2 class="entry__title"><a href="<?= e($m['url']) ?>"><?= e($m['title']) ?></a></h2>
            <div class="entry__meta">
              <span class="entry__meta-cat">
                <?php foreach ($m['categories'] as $c): ?>
                  <a href="index.php?category=<?= urlencode($c) ?>"><?= e($c) ?></a>
                <?php endforeach; ?>
              </span>
              <span class="entry__meta-date">
                <a href="<?= e($m['url']) ?>"><?= date('M d, Y', strtotime($m['date'] ?? 'today')) ?></a>
              </span>
            </div>
          </div>
          <div class="entry__excerpt"><p><?= e($m['summary']) ?></p></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
  <?php $pager && include __DIR__.'/inc/pagination.php'; ?>
</div>
<?php include __DIR__.'/inc/footer.php';