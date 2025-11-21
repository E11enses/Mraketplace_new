<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

// settings
$postsDir = __DIR__ . '/posts';
$perPage  = 10;

// collect posts (YYYY-MM-DD-title.html)
$files = glob($postsDir . '/*.html') ?: [];
usort($files, function ($a, $b) {
    $fa = basename($a, '.html');
    $fb = basename($b, '.html');
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fa, $ma) &&
        preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fb, $mb)) {
        return strcmp($mb[1], $ma[1]); // newest first by filename date
    }
    return filemtime($b) <=> filemtime($a);
});

// pagination
$total  = count($files);
$pages  = max(1, (int)ceil($total / $perPage));
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;
$slice  = array_slice($files, $offset, $perPage);

include __DIR__ . '/inc/header.php';
?>
<div class="s-content">
  <div class="masonry-wrap">
    <div class="masonry">
      <div class="grid-sizer"></div>
<?php foreach ($slice as $file): 
    $html = file_get_contents($file);
    // title from first h1
    $title = 'Untitled';
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
        $title = trim(strip_tags($m[1]));
    }
    // summary from first p
    $summary = '';
    if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) {
        $summary = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }
    // date from filename
$name = basename($file, '.html');
$dateText = '';
if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $name, $m)) {
    $dateText = format_date_ru($m[1]); // 5 января 2025
}
    $viewUrl = 'post/' . rawurlencode(preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $name));
    // thumb from HTML comment <!-- thumb: path -->
    $thumb = '';
    if (preg_match('/<!--\s*thumb:\s*(.*?)\s*-->/', $html, $m)) {
        $thumb = trim($m[1]);
    }
?>
      <article class="masonry__brick entry format-standard animate-this">
        <?php if ($thumb): ?>
        <div class="entry__thumb">
          <a href="<?= htmlspecialchars($viewUrl) ?>" class="entry__thumb-link">
            <img src="<?= htmlspecialchars($thumb) ?>" alt="">
          </a>
        </div>
        <?php endif; ?>
        <div class="entry__text">
          <div class="entry__header">
            <h2 class="entry__title"><a href="<?= htmlspecialchars($viewUrl) ?>"><?= htmlspecialchars($title) ?></a></h2>
            <div class="entry__meta">
              <span class="entry__meta-cat"></span>
              <span class="entry__meta-date">
                <a href="<?= htmlspecialchars($viewUrl) ?>"><?= htmlspecialchars($dateText) ?></a>
              </span>
            </div>
          </div>
          <div class="entry__excerpt">
            <p><?= htmlspecialchars(mb_strimwidth($summary, 0, 200, '…', 'UTF-8')) ?></p>
          </div>
        </div>
      </article>
<?php endforeach; ?>
    </div>
  </div>

  <?php if ($pages > 1): ?>
  <div class="row">
    <div class="column large-full">
      <nav class="pgn"><ul>
        <?php if ($page > 1): ?>
          <li><a class="pgn__prev" href="?page=<?= $page - 1 ?>">Prev</a></li>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $pages; $i++): ?>
          <?php if ($i === $page): ?>
            <li><span class="pgn__num current"><?= $i ?></span></li>
          <?php else: ?>
            <li><a class="pgn__num" href="?page=<?= $i ?>"><?= $i ?></a></li>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $pages): ?>
          <li><a class="pgn__next" href="?page=<?= $page + 1 ?>">Next</a></li>
        <?php endif; ?>
      </ul></nav>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/footer.php';