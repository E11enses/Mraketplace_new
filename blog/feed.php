<?php
// /blog/feed.php
ini_set('display_errors', 0);
date_default_timezone_set('Europe/Moscow');

require __DIR__ . '/inc/functions.php';

$siteUrl   = 'https://' . $_SERVER['HTTP_HOST'];
$blogUrl   = $siteUrl . '/blog/';
$feedUrl   = $siteUrl . '/blog/feed.xml';
$postsDir  = __DIR__ . '/posts';
$maxItems  = 50; // how many latest posts to include

// collect files and sort newest first
$files = glob($postsDir . '/*.html') ?: [];
usort($files, function ($a, $b) {
    $fa = basename($a, '.html'); $fb = basename($b, '.html');
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fa, $ma) && preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fb, $mb)) {
        return strcmp($mb[1], $ma[1]);
    }
    return filemtime($b) <=> filemtime($a);
});
$files = array_slice($files, 0, $maxItems);

// channel meta
$channelTitle       = 'Блог МРАКЕТПЛЕЙСЫ';
$channelDescription = 'Статьи о фулфилменте и работе с маркетплейсами';
$channelLanguage    = 'ru-RU';
$lastBuildDateRfc   = date(DATE_RSS);

// namespaces for content and yandex
header('Content-Type: application/rss+xml; charset=UTF-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:yandex="http://news.yandex.ru"
     xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title><?= e($channelTitle) ?></title>
    <link><?= e($blogUrl) ?></link>
    <description><?= e($channelDescription) ?></description>
    <language><?= e($channelLanguage) ?></language>
    <lastBuildDate><?= e($lastBuildDateRfc) ?></lastBuildDate>
    <docs>https://validator.w3.org/feed/docs/rss2.html</docs>
    <generator>Custom PHP RSS</generator>

<?php
foreach ($files as $file) {
    $html = file_get_contents($file);
    $meta = parse_meta_from_html($html);

    // title
    $title = '';
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
        $title = trim(strip_tags($m[1]));
    }
    if ($title === '' && preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $html, $m)) {
        $title = trim(strip_tags($m[1]));
    }

    // slug and link
    $name = basename($file, '.html');
    $slug = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $name);
    $link = $siteUrl . '/blog/post/' . rawurlencode($slug);

    // dates
    // pubDate must be RFC‑822/RFC‑1123
    $pubTs = filemtime($file);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $name, $mm)) {
        $pubTs = strtotime($mm[1] . ' 00:00:00 Europe/Moscow');
    }
    $pubDateRfc = date(DATE_RSS, $pubTs);

    // description: first paragraph (short)
    $summary = '';
    if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) {
        $summary = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }
    $summaryShort = function_exists('mb_strimwidth')
        ? mb_strimwidth($summary, 0, 240, '…', 'UTF-8')
        : substr($summary, 0, 240) . '…';

    // content: full HTML of the post (wrapped in CDATA)
    // You already render clean HTML in files; we can include it as-is.
    // Optionally remove outer wrappers if you added them; otherwise include full.
    $fullHtml = $html;

    // yandex:full-text – plain text full content (optional but recommended)
    $fullText = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

    // image
    $img = $meta['thumb'] ?? '';
    if ($img !== '' && strpos($img, 'http') !== 0) {
        $img = $siteUrl . $img;
    }

    // guid: stable unique id (use post link)
    $guid = $link;
?>
    <item>
      <title><?= e($title ?: $slug) ?></title>
      <link><?= e($link) ?></link>
      <guid isPermaLink="true"><?= e($guid) ?></guid>
      <pubDate><?= e($pubDateRfc) ?></pubDate>
      <description><![CDATA[<?= $summaryShort ?>]]></description>

      <?php if (!empty($img)): ?>
      <enclosure url="<?= e($img) ?>" type="image/jpeg" />
      <media:content url="<?= e($img) ?>" medium="image" />
      <?php endif; ?>

      <?php if (!empty($meta['categories'])): ?>
        <?php foreach ($meta['categories'] as $c): ?>
      <category><?= e($c) ?></category>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!empty($meta['tags'])): ?>
        <?php foreach ($meta['tags'] as $t): ?>
      <category><?= e($t) ?></category>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Full content for aggregators -->
      <content:encoded><![CDATA[<?= $fullHtml ?>]]></content:encoded>
      <yandex:full-text><![CDATA[<?= $fullText ?>]]></yandex:full-text>
    </item>

<?php } // foreach ?>
  </channel>
</rss>