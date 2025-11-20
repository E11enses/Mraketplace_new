<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

$postsDir = __DIR__ . '/posts';
$fname = basename($_GET['f'] ?? '');
$path  = realpath($postsDir . '/' . $fname);
if (!$fname || !$path || strpos($path, realpath($postsDir)) !== 0 || !is_file($path)) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}
$html = file_get_contents($path);

// Derive title for <title> tag (optional)
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