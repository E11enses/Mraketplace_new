<?php
// /blog/sitemap.php
ini_set('display_errors', 0);
date_default_timezone_set('Europe/Moscow');

require __DIR__ . '/inc/functions.php';

$host     = $_SERVER['HTTP_HOST'];
$origin   = 'https://' . $host;
$blogBase = $origin . '/blog';
$postsDir = __DIR__ . '/posts';
$nowIso   = date('c');

// collect posts
$files = glob($postsDir . '/*.html') ?: [];
usort($files, function ($a, $b) {
    $fa = basename($a, '.html'); $fb = basename($b, '.html');
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fa, $ma) &&
        preg_match('/^(\d{4}-\d{2}-\d{2})-/', $fb, $mb)) {
        return strcmp($mb[1], $ma[1]); // newer first
    }
    return filemtime($b) <=> filemtime($a);
});

// build URL list
$urls = [];

// blog home
$urls[] = [
    'loc'        => $blogBase . '/',
    'lastmod'    => $nowIso,
    'changefreq' => 'daily',
    'priority'   => '0.8',
];

// feed
$urls[] = [
    'loc'        => $blogBase . '/feed.xml',
    'lastmod'    => $nowIso,
    'changefreq' => 'hourly',
    'priority'   => '0.3',
];

// optional taxonomy index placeholders (they can render via taxonomy.php)
$urls[] = [
    'loc'        => $blogBase . '/category/',
    'lastmod'    => $nowIso,
    'changefreq' => 'weekly',
    'priority'   => '0.2',
];
$urls[] = [
    'loc'        => $blogBase . '/tag/',
    'lastmod'    => $nowIso,
    'changefreq' => 'weekly',
    'priority'   => '0.2',
];

// collect categories/tags
$catSet = [];
$tagSet = [];

foreach ($files as $file) {
    $name = basename($file, '.html');
    $slug = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $name);

    // lastmod from mtime and filename date
    $ts = @filemtime($file) ?: time();
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', $name, $mm)) {
        $ts = max($ts, strtotime($mm[1] . ' 00:00:00 Europe/Moscow'));
    }
    $lastmod = date('c', $ts);

    // post url
    $urls[] = [
        'loc'        => $blogBase . '/post/' . rawurlencode($slug),
        'lastmod'    => $lastmod,
        'changefreq' => 'monthly',
        'priority'   => '0.6',
    ];

    // parse meta for taxonomies
    $html = file_get_contents($file);
    $meta = parse_meta_from_html($html) ?? [];
    foreach (($meta['categories'] ?? []) as $c) {
        if ($c !== '') $catSet[$c] = true;
    }
    foreach (($meta['tags'] ?? []) as $t) {
        if ($t !== '') $tagSet[$t] = true;
    }
}

// add categories
foreach (array_keys($catSet) as $cat) {
    $urls[] = [
        'loc'        => $blogBase . '/category/' . rawurlencode($cat),
        'lastmod'    => $nowIso,
        'changefreq' => 'weekly',
        'priority'   => '0.5',
    ];
}

// add tags
foreach (array_keys($tagSet) as $tag) {
    $urls[] = [
        'loc'        => $blogBase . '/tag/' . rawurlencode($tag),
        'lastmod'    => $nowIso,
        'changefreq' => 'weekly',
        'priority'   => '0.4',
    ];
}

// output XML
header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?= e($u['loc']) ?></loc>
    <?php if (!empty($u['lastmod'])): ?><lastmod><?= e($u['lastmod']) ?></lastmod><?php endif; ?>
    <?php if (!empty($u['changefreq'])): ?><changefreq><?= e($u['changefreq']) ?></changefreq><?php endif; ?>
    <?php if (!empty($u['priority'])): ?><priority><?= e($u['priority']) ?></priority><?php endif; ?>
  </url>
<?php endforeach; ?>
</urlset>