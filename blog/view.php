<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

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

// Optional: set page title from first H1
$pageTitle = 'Post';
if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
    $pageTitle = trim(strip_tags($m[1])) . ' - Typerite';
}

include __DIR__ . '/inc/header.php';
?>
<div class="s-content content">
  <main class="row content__page">
    <article class="column large-full entry format-standard">
      <?= $html ?>
    </article>
  </main>
</div>
<?php include __DIR__ . '/inc/footer.php';