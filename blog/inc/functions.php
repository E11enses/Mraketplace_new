<?php
// Common helpers for the blog

// Escape
if (!function_exists('e')) {
    function e(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

// Parse YAML-like front matter at top of file
if (!function_exists('parse_front_matter')) {
    function parse_front_matter(string $content): array {
        if (preg_match('/^---\s*(.*?)\s*---\s*(.*)$/s', $content, $m)) {
            $raw  = trim($m[1]);
            $body = $m[2];
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
}

// Parse simple HTML comment metadata inside the post
// <!-- categories: A, B -->, <!-- tags: x, y -->, <!-- thumb: /path.jpg -->, <!-- type: standard -->
if (!function_exists('parse_meta_from_html')) {
    function parse_meta_from_html(string $html): array {
        $meta = [
            'categories' => [],
            'tags'       => [],
            'thumb'      => '',
            'type'       => 'standard',
        ];
        if (preg_match('/<!--\s*categories:\s*(.*?)\s*-->/is', $html, $m)) {
            $meta['categories'] = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
        }
        if (preg_match('/<!--\s*tags:\s*(.*?)\s*-->/is', $html, $m)) {
            $meta['tags'] = array_values(array_filter(array_map('trim', explode(',', $m[1]))));
        }
        if (preg_match('/<!--\s*thumb:\s*(.*?)\s*-->/is', $html, $m)) {
            $meta['thumb'] = trim($m[1]);
        }
        if (preg_match('/<!--\s*type:\s*([a-z]+)\s*-->/i', $html, $m)) {
            $meta['type'] = strtolower(trim($m[1]));
        }
        return $meta;
    }
}

// Convert filename to slug (strip leading YYYY-MM-DD- and .html)
if (!function_exists('filename_to_slug')) {
    function filename_to_slug(string $file): string {
        $name = basename($file, '.html');
        $slug = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', $name);
        return $slug;
    }
}

// Russian date formatter (Intl if available, otherwise safe fallback)
if (!function_exists('format_date_ru')) {
    function format_date_ru(string $ymd): string {
        $ts = strtotime($ymd);
        if (!$ts) return $ymd;

        // Prefer IntlDateFormatter if the intl extension exists
        if (class_exists('IntlDateFormatter')) {
            $fmt = new IntlDateFormatter(
                'ru_RU',
                IntlDateFormatter::MEDIUM,
                IntlDateFormatter::NONE,
                date_default_timezone_get() ?: 'Europe/Moscow',
                IntlDateFormatter::GREGORIAN,
                'd MMM yyyy' // e.g. 5 янв. 2025; use 'd MMMM yyyy' for full month: "5 января 2025"
            );
            $out = $fmt->format($ts);
            if ($out !== false) {
                return $out;
            }
        }

        // Fallback manual month map (short form)
        $mapShort = [
            'Jan'=>'янв.','Feb'=>'февр.','Mar'=>'мар.','Apr'=>'апр.','May'=>'мая','Jun'=>'июн.',
            'Jul'=>'июл.','Aug'=>'авг.','Sep'=>'сент.','Oct'=>'окт.','Nov'=>'нояб.','Dec'=>'дек.'
        ];
        $monEng = date('M', $ts);
        $day    = ltrim(date('d', $ts), '0');
        $year   = date('Y', $ts);
        return $day . ' ' . ($mapShort[$monEng] ?? $monEng) . ' ' . $year;
    }
}

// Load and normalize posts from a directory
if (!function_exists('load_posts')) {
    function load_posts(string $dir): array {
        $posts = [];
        foreach (glob($dir . '/*.html') as $file) {
            $content = file_get_contents($file);
            [$meta, $body] = parse_front_matter($content);

            // Derive meta from filename
            $fn = basename($file, '.html');
            if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $fn, $m)) {
                $meta['date'] = $meta['date'] ?? $m[1];
                $meta['slug'] = $meta['slug'] ?? $m[2];
            } else {
                $meta['slug'] = $meta['slug'] ?? $fn;
            }

            // Derive title from first H1 if missing
            if (!isset($meta['title']) && preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $body, $mm)) {
                $meta['title'] = trim(strip_tags($mm[1]));
            }
            $meta['title'] = $meta['title'] ?? $meta['slug'];

            // Summary from first 200 chars of body (no HTML)
            $plain = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
            if (!isset($meta['summary']) || $meta['summary'] === '') {
                // Use mb_substr if available, else substr
                if (function_exists('mb_substr')) {
                    $meta['summary'] = mb_substr($plain, 0, 200) . '…';
                } else {
                    $meta['summary'] = substr($plain, 0, 200) . '…';
                }
            }

            // Arrays from comma-separated front matter
            $meta['categories'] = array_values(array_filter(array_map('trim', explode(',', $meta['categories'] ?? ''))));
            $meta['tags']       = array_values(array_filter(array_map('trim', explode(',', $meta['tags'] ?? ''))));

            // Thumb (from front matter only here; you can also merge HTML-comment meta if desired)
            $meta['thumb'] = $meta['thumb'] ?? '';

            // URL (legacy; you likely use pretty URLs elsewhere)
            $meta['url'] = 'post.php?slug=' . rawurlencode($meta['slug']);

            $posts[] = ['meta' => $meta, 'body' => $body, 'file' => $file];
        }

        // Sort by date desc (fallback to mtime)
        usort($posts, function ($a, $b) {
            $da = $a['meta']['date'] ?? date('Y-m-d', filemtime($a['file']));
            $db = $b['meta']['date'] ?? date('Y-m-d', filemtime($b['file']));
            return strtotime($db) <=> strtotime($da);
        });

        return $posts;
    }
}

// Simple paginator
if (!function_exists('paginate')) {
    function paginate(array $items, int $page, int $per_page): array {
        $total  = count($items);
        $pages  = max(1, (int)ceil($total / $per_page));
        $page   = max(1, min($page, $pages));
        $offset = ($page - 1) * $per_page;
        return [
            'items' => array_slice($items, $offset, $per_page),
            'page'  => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }
}

// Find a post by slug and return it with prev/next
if (!function_exists('find_post_by_slug')) {
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
}