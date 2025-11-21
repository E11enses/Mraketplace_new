<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

require __DIR__ . '/inc/functions.php';

// settings
$postsDir = __DIR__ . '/posts';
$perPage  = 10;

// collect posts (YYYY-MM-DD-slug.html)
$files = glob($postsDir . '/*.html') ?: [];

// sort newest first by filename date or mtime
usort($files, function ($a, $b) {
    $fa = basename($a, '.html');
    $fb = basename($b, '.html');
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fa, $ma) &&
        preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fb, $mb)) {
        return strcmp($mb[1], $ma[1]); // older -> newer
    }
    return filemtime($b) <=> filemtime($a); // fallback
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

<?php
foreach ($slice as $file):
    // load HTML of this post
    $html = file_get_contents($file);

    // parse meta from HTML comments
    $meta = parse_meta_from_html($html);
    if (!is_array($meta)) {
        $meta = ['categories' => [], 'tags' => [], 'thumb' => '', 'type' => 'standard'];
    }

    // slug, pretty URL
    $name   = basename($file, '.html');
    $slug   = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $name);
    $pretty = '/blog/post/' . rawurlencode($slug);

    // date text (RU)
    $dateText = '';
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $name, $m)) {
        $dateText = format_date_ru($m[1]);
    }

    // title from H1/H2
    $title = '';
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
        $title = trim(strip_tags($m[1]));
    }
    if ($title === '' && preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $html, $m)) {
        $title = trim(strip_tags($m[1]));
    }
    if ($title === '') {
        $title = $slug;
    }

    // summary from first paragraph
    $summary = '';
    if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) {
        $summary = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }

    // thumb from comment (if any)
    $thumb = $meta['thumb'] ?? '';

    // which card type (optional usage)
    $type = $meta['type'] ?? 'standard';
    // for this simple implementation, render all as standard cards;
    // you can switch on $type to output video/quote/link formats.
?>
      <article class="masonry__brick entry format-standard animate-this">
        <?php if (!empty($thumb)): ?>
        <div class="entry__thumb">
          <a href="<?= e($pretty) ?>" class="entry__thumb-link">
            <img src="<?= e($thumb) ?>" alt="">
          </a>
        </div>
        <?php endif; ?>

        <div class="entry__text">
          <div class="entry__header">
            <h2 class="entry__title"><a href="<?= e($pretty) ?>"><?= e($title) ?></a></h2>

            <div class="entry__meta">
              <span class="entry__meta-cat">
                <?php if (!empty($meta['categories']) && is_array($meta['categories'])): ?>
                  <?php foreach ($meta['categories'] as $c): ?>
                    <a href="/blog/category/<?= urlencode($c) ?>"><?= e($c) ?></a>
                  <?php endforeach; ?>
                <?php endif; ?>
              </span>
              <span class="entry__meta-date">
                <a href="<?= e($pretty) ?>"><?= e($dateText) ?></a>
              </span>
            </div>
          </div>

          <div class="entry__excerpt">
            <p><?= e(function_exists('mb_strimwidth') ? mb_strimwidth($summary, 0, 200, '…', 'UTF-8') : substr($summary, 0, 200) . '…') ?></p>
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