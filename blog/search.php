<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

$postsDir = __DIR__ . '/posts';
$perPage  = 10;

$q = trim($_GET['q'] ?? '');
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Load posts list (same as index)
$files = glob($postsDir . '/*.html') ?: [];
usort($files, function ($a, $b) {
    $fa = basename($a, '.html'); $fb = basename($b, '.html');
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fa, $ma) &&
        preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fb, $mb)) {
        return strcmp($mb[1], $ma[1]);
    }
    return filemtime($b) <=> filemtime($a);
});

// Filter by query
$results = [];
if ($q !== '') {
    $needle = mb_strtolower($q, 'UTF-8');
    foreach ($files as $file) {
        $html = file_get_contents($file);

        // Extract title, first paragraph summary, and a body text version for matching
        $title = '';
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $title = trim(strip_tags($m[1]));
        }
        $summary = '';
        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) {
            $summary = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
        }
        $bodyText = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

        $hay = mb_strtolower($title . ' ' . $summary . ' ' . $bodyText, 'UTF-8');
        if (mb_strpos($hay, $needle, 0, 'UTF-8') !== false) {
            $name = basename($file, '.html');
            $dateText = '';
            if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $name, $mm)) {
                $dateText = date('M d, Y', strtotime($mm[1]));
            }
            $slug = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $name);
            $prettyUrl = 'post/' . rawurlencode($slug);

            // Optional thumb via comment <!-- thumb: path -->
            $thumb = '';
            if (preg_match('/<!--\s*thumb:\s*(.*?)\s*-->/', $html, $mthumb)) {
                $thumb = trim($mthumb[1]);
            }

            $results[] = [
                'title'   => $title ?: $slug,
                'summary' => $summary,
                'date'    => $dateText,
                'url'     => $prettyUrl,
                'thumb'   => $thumb,
            ];
        }
    }
}

// Pagination for results
$total = count($results);
$pages = max(1, (int)ceil($total / $perPage));
$page  = max(1, min($page, $pages));
$offset = ($page - 1) * $perPage;
$slice  = array_slice($results, $offset, $perPage);

// Include header
include __DIR__ . '/inc/header.php';
?>
<div class="s-content">
  <header class="listing-header">
    <h1 class="h2">Поиск: <?= htmlspecialchars($q ?: 'All', ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($q !== ''): ?>
      <p><?= $total ?> результатов<?= $total == 1 ? '' : 's' ?> найдено</p>
    <?php endif; ?>
  </header>

  <div class="masonry-wrap">
    <div class="masonry">
      <div class="grid-sizer"></div>
      <?php if ($q === ''): ?>
        <div class="column large-full">
          <p>Введите поисковый запрос.</p>
        </div>
      <?php elseif ($total === 0): ?>
        <div class="column large-full">
          <p>Не нашли результатов для "<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>".</p>
        </div>
      <?php else: ?>
        <?php foreach ($slice as $r): ?>
        <article class="masonry__brick entry format-standard animate-this">
          <?php if (!empty($r['thumb'])): ?>
          <div class="entry__thumb">
            <a href="<?= htmlspecialchars($r['url']) ?>" class="entry__thumb-link">
              <img src="<?= htmlspecialchars($r['thumb']) ?>" alt="">
            </a>
          </div>
          <?php endif; ?>

          <div class="entry__text">
            <div class="entry__header">
              <h2 class="entry__title">
                <a href="<?= htmlspecialchars($r['url']) ?>"><?= htmlspecialchars($r['title']) ?></a>
              </h2>
              <div class="entry__meta">
                <span class="entry__meta-cat"></span>
                <span class="entry__meta-date"><a href="<?= htmlspecialchars($r['url']) ?>"><?= htmlspecialchars($r['date']) ?></a></span>
              </div>
            </div>
            <div class="entry__excerpt">
              <p><?= htmlspecialchars($r['summary'] ?: '', ENT_QUOTES, 'UTF-8') ?></p>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($q !== '' && $pages > 1): ?>
  <div class="row"><div class="column large-full">
    <nav class="pgn"><ul>
      <?php if ($page > 1): ?>
        <li><a class="pgn__prev" href="?q=<?= urlencode($q) ?>&page=<?= $page - 1 ?>">Предыдущая</a></li>
      <?php endif; ?>
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <?php if ($i === $page): ?>
          <li><span class="pgn__num current"><?= $i ?></span></li>
        <?php else: ?>
          <li><a class="pgn__num" href="?q=<?= urlencode($q) ?>&page=<?= $i ?>"><?= $i ?></a></li>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $pages): ?>
        <li><a class="pgn__next" href="?q=<?= urlencode($q) ?>&page=<?= $page + 1 ?>">Следующая</a></li>
      <?php endif; ?>
    </ul></nav>
  </div></div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/footer.php';