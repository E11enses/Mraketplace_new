<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

require __DIR__.'/inc/functions.php';
include __DIR__.'/inc/header.php';

$postsDir = __DIR__.'/posts';
$type  = ($_GET['type'] ?? '') === 'tag' ? 'tag' : 'category';
$value = trim($_GET['value'] ?? '');

$files = glob($postsDir.'/*.html') ?: [];
usort($files, function ($a,$b){
    $fa = basename($a, '.html'); $fb = basename($b, '.html');
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fa, $ma) && preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fb, $mb)) {
        return strcmp($mb[1], $ma[1]);
    }
    return filemtime($b) <=> filemtime($a);
});

$needle = mb_strtolower($value, 'UTF-8');
$results = [];

foreach ($files as $file) {
    $html = file_get_contents($file);
    $meta = parse_meta_from_html($html);

    $list = $type === 'tag' ? $meta['tags'] : $meta['categories'];
    $match = false;
    foreach ($list as $v) {
        if (mb_strtolower($v, 'UTF-8') === $needle) { $match = true; break; }
    }
    if (!$match) continue;

    $name = basename($file, '.html');
    $slug = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $name);
    $pretty = '/blog/post/' . rawurlencode($slug);

    $dateText = '';
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $name, $m)) {
        $dateText = format_date_ru($m[1]);
    }

    $title = '';
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) $title = trim(strip_tags($m[1]));
    if ($title === '' && preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $html, $m)) $title = trim(strip_tags($m[1]));
    $summary = '';
    if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) $summary = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    $thumb = $meta['thumb'] ?? '';

    $results[] = compact('title', 'summary', 'dateText', 'pretty', 'thumb', 'meta');
}
?>
<div class="s-content">
  <header class="listing-header">
    <h1 class="h2"><?= $type === 'tag' ? 'Тег' : 'Категория' ?>: <?= e($value) ?></h1>
  </header>

  <div class="masonry-wrap"><div class="masonry"><div class="grid-sizer"></div>
  <?php if (empty($results)): ?>
    <div class="column large-full"><p>Постов нет.</p></div>
  <?php else: foreach ($results as $r): ?>
    <article class="masonry__brick entry format-standard animate-this">
      <?php if (!empty($r['thumb'])): ?>
      <div class="entry__thumb">
        <a href="<?= e($r['pretty']) ?>" class="entry__thumb-link">
          <img src="<?= e($r['thumb']) ?>" alt="">
        </a>
      </div>
      <?php endif; ?>
      <div class="entry__text">
        <div class="entry__header">
          <h2 class="entry__title"><a href="<?= e($r['pretty']) ?>"><?= e($r['title'] ?: 'Пост') ?></a></h2>
          <div class="entry__meta">
            <span class="entry__meta-cat">
              <?php foreach (($r['meta']['categories'] ?? []) as $c): ?>
                <a href="/blog/category/<?= urlencode($c) ?>"><?= e($c) ?></a>
              <?php endforeach; ?>
            </span>
            <span class="entry__meta-date"><a href="<?= e($r['pretty']) ?>"><?= e($r['dateText']) ?></a></span>
          </div>
        </div>
        <div class="entry__excerpt"><p><?= e($r['summary']) ?></p></div>
      </div>
    </article>
  <?php endforeach; endif; ?>
  </div></div>
</div>
<?php include __DIR__.'/inc/footer.php';