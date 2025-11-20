<?php
$config = require __DIR__.'/config.php';
require __DIR__.'/inc/functions.php';
$all  = load_posts($config['posts_dir']);
$slug = $_GET['slug'] ?? '';
[$post, $prev, $next] = find_post_by_slug($all, $slug);
if (!$post) { http_response_code(404); echo 'Not Found'; exit; }
$m = $post['meta']; $body = $post['body'];

$config['site_name'] = $m['title'] . ' - Typerite';
include __DIR__.'/inc/header.php';
?>
<div class="s-content content">
  <main class="row content__page">
    <article class="column large-full entry format-standard">
      <div class="content__page-header entry__header">
        <h1 class="display-1 entry__title"><?= e($m['title']) ?></h1>
        <ul class="entry__header-meta">
          <li class="date"><?= date('F d, Y', strtotime($m['date'] ?? 'today')) ?></li>
          <li class="cat-links">
            <?php foreach ($m['categories'] as $c): ?>
              <a href="index.php?category=<?= urlencode($c) ?>"><?= e($c) ?></a>
            <?php endforeach; ?>
          </li>
        </ul>
      </div>
      <div class="entry__content">
        <?= $body ?>
        <?php if (!empty($m['tags'])): ?>
        <p class="entry__tags"><span>Post Tags</span>
          <span class="entry__tag-list">
            <?php foreach ($m['tags'] as $t): ?>
              <a href="index.php?tag=<?= urlencode($t) ?>"><?= e($t) ?></a>
            <?php endforeach; ?>
          </span>
        </p>
        <?php endif; ?>
      </div>

      <div class="entry__pagenav"><div class="entry__nav">
        <div class="entry__prev">
          <?php if ($prev): ?>
          <a href="post.php?slug=<?= urlencode($prev['slug']) ?>"><span>Previous Post</span><?= e($prev['title'] ?? $prev['slug']) ?></a>
          <?php endif; ?>
        </div>
        <div class="entry__next">
          <?php if ($next): ?>
          <a href="post.php?slug=<?= urlencode($next['slug']) ?>"><span>Next Post</span><?= e($next['title'] ?? $next['slug']) ?></a>
          <?php endif; ?>
        </div>
      </div></div>
    </article>
  </main>
</div>
<?php include __DIR__.'/inc/footer.php';