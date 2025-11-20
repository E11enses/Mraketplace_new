<?php
// public/api/save.php
// Returns: { ok: true, slug: "...", shortUrl: "https://host/s/slug" }

header('Content-Type: application/json; charset=utf-8');

// Optional CORS if needed:
// header('Access-Control-Allow-Origin: *');
// if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

// Limits
$MAX_COMMENT = 10000;      // 10K chars
$MAX_PAYLOAD_BYTES = 200 * 1024; // 200 KB
$TTL_SECONDS = 30 * 86400; // 30 days
$MAX_ENTRIES = 10000;      // cap

// Read raw body with a size check
$raw = file_get_contents('php://input', false, null, 0, $MAX_PAYLOAD_BYTES + 1);
if ($raw === false || strlen($raw) > $MAX_PAYLOAD_BYTES) {
  http_response_code(413);
  echo json_encode(['ok' => false, 'error' => 'Payload too large']);
  exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Bad JSON']);
  exit;
}

// Whitelist fields and sanitize
function pick($arr, $key, $maxLen = 100) {
  if (!isset($arr[$key]) || $arr[$key] === null) return '';
  // Normalize to string and trim excessive length (avoid junk)
  $val = (string)$arr[$key];
  // Optional: normalize commas in numeric fields client-side; here we just cap length
  return mb_substr($val, 0, $maxLen, 'UTF-8');
}

function sanitize_state($d, $MAX_COMMENT) {
  return [
    'price' => pick($d, 'price', 32),
    'tax'   => pick($d, 'tax', 16), // OSNO/USN6/USN15
    'vat'   => pick($d, 'vat', 8),
    'ref'   => pick($d, 'ref', 16),
    'drr'   => pick($d, 'drr', 16),
    'cogs'  => pick($d, 'cogs', 32),
    'fulf'  => pick($d, 'fulf', 32),
    'last'  => pick($d, 'last', 32),
    'ret'   => pick($d, 'ret', 16),
    'retc'  => pick($d, 'retc', 32),
    'inb'   => pick($d, 'inb', 32),
    'stor'  => pick($d, 'stor', 32),
    'pack'  => pick($d, 'pack', 32),
    'other' => pick($d, 'other', 32),
    'cmt'   => mb_substr((string)($d['cmt'] ?? ''), 0, $MAX_COMMENT, 'UTF-8'),
  ];
}

$state = sanitize_state($data, $MAX_COMMENT);

// Load store
$storeFile = __DIR__ . '/../data/store.json';
$storeDir = dirname($storeFile);
if (!file_exists($storeDir)) {
  @mkdir($storeDir, 0775, true);
}
$store = [];
if (file_exists($storeFile)) {
  $json = file_get_contents($storeFile);
  $store = json_decode($json, true);
  if (!is_array($store)) $store = [];
}

// Prune by TTL and max entries
$now = time();
// TTL prune
foreach ($store as $k => $item) {
  $ts = isset($item['_ts']) ? (int)$item['_ts'] : 0;
  if ($ts && ($now - $ts) > $TTL_SECONDS) {
    unset($store[$k]);
  }
}
// Max entries prune
if (count($store) > $MAX_ENTRIES) {
  // sort by timestamp ascending and remove oldest extra
  uasort($store, function($a, $b) {
    $ta = isset($a['_ts']) ? (int)$a['_ts'] : 0;
    $tb = isset($b['_ts']) ? (int)$b['_ts'] : 0;
    return $ta <=> $tb;
  });
  $excess = count($store) - $MAX_ENTRIES;
  while ($excess-- > 0) {
    $firstKey = array_key_first($store);
    if ($firstKey === null) break;
    unset($store[$firstKey]);
  }
}

// Generate slug
function gen_slug($len = 7) {
  // Base62-ish
  $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  $out = '';
  for ($i = 0; $i < $len; $i++) {
    $out .= $chars[random_int(0, strlen($chars) - 1)];
  }
  return $out;
}
$slug = gen_slug(7);
while (array_key_exists($slug, $store)) {
  $slug = gen_slug(7);
}

// Save
$state['_ts'] = $now; // store timestamp for TTL/pruning
$store[$slug] = $state;
file_put_contents($storeFile, json_encode($store, JSON_UNESCAPED_UNICODE));

// Build short URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$shortUrl = $scheme . '://' . $host . '/s/' . $slug;

echo json_encode(['ok' => true, 'slug' => $slug, 'shortUrl' => $shortUrl], JSON_UNESCAPED_UNICODE);