<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

require __DIR__ . '/inc/functions.php';

$postsDir = __DIR__ . '/posts';

// Accept either pretty slug (?slug=...) or fallback (?f=filename.html)
$slug = $_GET['slug'] ?? null;
$fname = $_GET['f'] ?? null;

$path = null;

if ($slug) {
    // Find a post whose filename (without date and .html) matches the slug
    $files = glob($postsDir . '/*.html') ?: [];
    foreach ($files as $file) {
        $base = basename($file, '.html');
        $slugFromFile = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $base);
        if ($slugFromFile === $slug) {
            $path = $file;
            break;
        }
    }
} elseif ($fname) {
    $safe = basename($fname);
    $candidate = realpath($postsDir . '/' . $safe);
    if ($candidate && strpos($candidate, realpath($postsDir)) === 0 && is_file($candidate)) {
        $path = $candidate;
    }
}

if (!$path) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

$html = file_get_contents($path);
$meta = parse_meta_from_html($html);

// Optional: set page title from first H1
$pageTitle = 'Post';
if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
    $pageTitle = trim(strip_tags($m[1])) . ' - Блог Мракетплейсы';
}

include __DIR__ . '/inc/header.php';
?>
<div class="s-content content">
  <main class="row content__page">
    <article class="column large-full entry format-standard">
      <div class="entry__content">
        <?= $html ?>

        <?php if (!empty($meta['tags'])): ?>
        <p class="entry__tags">
          <span>Теги</span>
          <span class="entry__tag-list">
            <?php foreach ($meta['tags'] as $t): ?>
              <a href="/blog/tag/<?= urlencode($t) ?>"><?= e($t) ?></a>
            <?php endforeach; ?>
          </span>
        </p>
        <?php endif; ?>
      </div>
    </article>
  </main>
</div>
<?php include __DIR__ . '/inc/footer.php';