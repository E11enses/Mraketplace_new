<?php
// /blog/feed.php
ini_set('display_errors', 0);
date_default_timezone_set('Europe/Moscow');

require __DIR__ . '/inc/functions.php';

$host     = $_SERVER['HTTP_HOST'];
$siteUrl  = 'https://' . $host;
$blogUrl  = $siteUrl . '/blog/';
$feedUrl  = $siteUrl . '/blog/feed.xml';
$postsDir = __DIR__ . '/posts';
$maxItems = 50;

// Helper: make URLs absolute inside HTML (src, href)
function absolutize_urls(string $html, string $base): string {
    // src and href that don't start with http/https/mailto/tel/# become absolute
    $cb = function ($m) use ($base) {
        $attr = $m[1];
        $url  = trim($m[2]);
        if ($url === '' || strpos($url, '#') === 0) return $m[0]; // leave anchors
        if (preg_match('~^(?:https?:|mailto:|tel:)~i', $url)) return $m[0];
        // if starts with /, prepend origin; else treat as relative to /blog/
        if ($url[0] === '/') {
            $abs = rtrim($base, '/') . $url;
        } else {
            // feed is at /blog/; most content URLs are relative to /blog/
            $abs = rtrim($base, '/') . '/blog/' . $url;
        }
        return $attr . '="' . htmlspecialchars($abs, ENT_QUOTES, 'UTF-8') . '"';
    };
    $html = preg_replace_callback('~\b(src|href)\s*=\s*"([^"]+)"~i', $cb, $html);
    $html = preg_replace_callback("~\b(src|href)\s*=\s*'([^']+)'~i", $cb, $html);
    return $html;
}

// Collect files and sort newest first
$files = glob($postsDir . '/*.html') ?: [];
usort($files, function ($a, $b) {
    $fa = basename($a, '.html'); $fb = basename($b, '.html');
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fa, $ma) && preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fb, $mb)) {
        return strcmp($mb[1], $ma[1]);
    }
    return filemtime($b) <=> filemtime($a);
});
$files = array_slice($files, 0, $maxItems);

// Channel meta
$channelTitle       = 'Блог МРАКЕТПЛЕЙСЫ';
$channelDescription = 'Статьи о фулфилменте и работе с маркетплейсами';
$channelLanguage    = 'ru-RU';
$lastBuildDateRfc   = date(DATE_RSS);

// Namespaces: content, yandex, media, atom
header('Content-Type: application/rss+xml; charset=UTF-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:yandex="http://news.yandex.ru"
     xmlns:media="http://search.yahoo.com/mrss/"
     xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title><?= e($channelTitle) ?></title>
    <link><?= e($blogUrl) ?></link>
    <description><?= e($channelDescription) ?></description>
    <language><?= e($channelLanguage) ?></language>
    <lastBuildDate><?= e($lastBuildDateRfc) ?></lastBuildDate>
    <docs>https://validator.w3.org/feed/docs/rss2.html</docs>
    <generator>Custom PHP RSS</generator>
    <atom:link href="<?= e($feedUrl) ?>" rel="self" type="application/rss+xml" />

<?php
foreach ($files as $file) {
    $html = file_get_contents($file);
    $meta = parse_meta_from_html($html);

    // Title
    $title = '';
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
        $title = trim(strip_tags($m[1]));
    }
    if ($title === '' && preg_match('/<h2[^>]*>(.*?)<\/h2>/is', $html, $m)) {
        $title = trim(strip_tags($m[1]));
    }

    // Slug and link
    $name = basename($file, '.html');
    $slug = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $name);
    $link = $siteUrl . '/blog/post/' . rawurlencode($slug);

    // Pub date
    $pubTs = 0;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $name, $mm)) {
        $pubTs = strtotime($mm[1] . ' 00:00:00 Europe/Moscow');
    }
    if (!$pubTs) {
        $pubTs = @filemtime($file) ?: time();
    }
    $pubDateRfc = date(DATE_RSS, $pubTs);

    // Summary
    $summary = '';
    if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) {
        $summary = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }
    $summaryShort = function_exists('mb_strimwidth')
        ? mb_strimwidth($summary, 0, 240, '…', 'UTF-8')
        : substr($summary, 0, 240) . '…';

    // Full HTML with absolute URLs
    $fullHtml = absolutize_urls($html, $siteUrl);

    // Plain full text for yandex
    $fullText = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

    // Image (absolute)
    $img = $meta['thumb'] ?? '';
    if ($img !== '' && strpos($img, 'http') !== 0) {
        $img = $siteUrl . $img;
    }

    // enclosure length (bytes) if we can read it
    $enclosureLength = '';
    if ($img) {
        $localPath = null;
        // map absolute URL to local path if it starts with site origin
        $prefix = $siteUrl;
        if (stripos($img, $prefix) === 0) {
            $rel = substr($img, strlen($prefix)); // starts with /...
            $localPath = $_SERVER['DOCUMENT_ROOT'] . $rel;
            if (is_file($localPath)) {
                $enclosureLength = (string) filesize($localPath);
            }
        }
    }

    // GUID
    $guid = $link;
?>
    <item>
      <title><?= e($title ?: $slug) ?></title>
      <link><?= e($link) ?></link>
      <guid isPermaLink="true"><?= e($guid) ?></guid>
      <pubDate><?= e($pubDateRfc) ?></pubDate>
      <description><![CDATA[<?= $summaryShort ?>]]></description>

      <?php if (!empty($img)): ?>
        <?php if ($enclosureLength !== ''): ?>
      <enclosure url="<?= e($img) ?>" type="image/jpeg" length="<?= e($enclosureLength) ?>" />
        <?php endif; ?>
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

      <content:encoded><![CDATA[<?= $fullHtml ?>]]></content:encoded>
      <yandex:full-text><![CDATA[<?= $fullText ?>]]></yandex:full-text>
    </item>

<?php } // foreach ?>
  </channel>
</rss>