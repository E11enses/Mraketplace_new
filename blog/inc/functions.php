<?php
function parse_front_matter(string $content): array {
  if (preg_match('/^---\s*(.*?)\s*---\s*(.*)$/s', $content, $m)) {
    $raw = trim($m[1]); $body = $m[2];
    $meta = [];
    foreach (preg_split('/\R+/', $raw) as $line) {
      if (strpos($line, ':') !== false) {
        [$k, $v] = array_map('trim', explode(':', $line, 2));
        $meta[strtolower($k)] = trim($v, " \t\"'");
      }
    }
    return [$meta, $body];
  }
  return [[], $content];
}

function load_posts(string $dir): array {
  $posts = [];
  foreach (glob($dir . '/*.html') as $file) {
    $content = file_get_contents($file);
    [$meta, $body] = parse_front_matter($content);
    $fn = basename($file, '.html');

    // derive date and slug from filename if present
    if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $fn, $m)) {
      $meta['date'] = $meta['date'] ?? $m[1];
      $meta['slug'] = $meta['slug'] ?? $m[2];
    } else {
      $meta['slug'] = $meta['slug'] ?? $fn;
    }

    // derive title from first H1 if not supplied
    if (!isset($meta['title']) && preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $body, $mm)) {
      $meta['title'] = trim(strip_tags($mm[1]));
    }
    $meta['title'] = $meta['title'] ?? $meta['slug'];

    // summary (or first 200 chars)
    $meta['summary'] = $meta['summary'] ?? mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($body))), 0, 200) . '…';

    // arrays
    $meta['categories'] = array_values(array_filter(array_map('trim', explode(',', $meta['categories'] ?? ''))));
    $meta['tags']       = array_values(array_filter(array_map('trim', explode(',', $meta['tags'] ?? ''))));

    // thumb (optional path in front matter)
    $meta['thumb'] = $meta['thumb'] ?? '';

    // url
    $meta['url'] = 'post.php?slug=' . rawurlencode($meta['slug']);

    $posts[] = ['meta' => $meta, 'body' => $body, 'file' => $file];
  }

  // sort by date desc (fallback to filemtime)
  usort($posts, function ($a, $b) {
    $da = $a['meta']['date'] ?? date('Y-m-d', filemtime($a['file']));
    $db = $b['meta']['date'] ?? date('Y-m-d', filemtime($b['file']));
    return strtotime($db) <=> strtotime($da);
  });

  return $posts;
}

function paginate(array $items, int $page, int $per_page): array {
  $total = count($items);
  $pages = max(1, (int)ceil($total / $per_page));
  $page  = max(1, min($page, $pages));
  $offset = ($page - 1) * $per_page;
  return [
    'items' => array_slice($items, $offset, $per_page),
    'page'  => $page,
    'pages' => $pages,
    'total' => $total,
  ];
}

function find_post_by_slug(array $posts, string $slug): array {
  foreach ($posts as $i => $p) {
    if ($p['meta']['slug'] === $slug) {
      $prev = $posts[$i - 1]['meta'] ?? null;
      $next = $posts[$i + 1]['meta'] ?? null;
      return [$p, $prev, $next];
    }
  }
  return [null, null, null];
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }