<?php
// api/wb-promotions-details.php
// Fetches detailed info for specific promotions.
// Usage example:
//   /api/wb-promotions-details.php?promotionIDs=1&promotionIDs=3&promotionIDs=64
//
// Notes:
// - Up to 100 promotionIDs allowed (per WB docs)
// - Token loading: ENV WB_ADS_TOKEN -> file api/wb_ads_token.php (plain text)
// - Header: Authorization: <token> (switch to X-Api-Key if your account requires it)

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// 1) Load token
$token = getenv('WB_ADS_TOKEN');
if (!$token) {
  $secretFile = __DIR__ . '/wb_ads_token.php';
  if (file_exists($secretFile)) {
    $token = trim((string)file_get_contents($secretFile));
  }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if (!$token) {
  http_response_code(500);
  echo json_encode(['error' => 'WB_ADS_TOKEN not configured']);
  exit;
}

// 2) Read promotionIDs from query (repeated param)
// Accept both promotionIDs[]=1&promotionIDs[]=2 and promotionIDs=1&promotionIDs=2
$ids = [];
if (isset($_GET['promotionIDs'])) {
  $raw = $_GET['promotionIDs'];
  if (is_array($raw)) {
    $ids = $raw;
  } else {
    // If a single comma-separated string is passed, split it
    // e.g., promotionIDs=1,2,3
    $ids = preg_split('/,/', (string)$raw);
  }
}
$ids = array_values(array_unique(array_filter(array_map(function ($v) {
  // keep only positive integer-like values
  if ($v === null) return null;
  if (is_numeric($v)) {
    $i = (int)$v;
    return $i > 0 ? $i : null;
  }
  return null;
}, $ids))));

if (count($ids) === 0) {
  http_response_code(400);
  echo json_encode(['error' => 'Missing required query params: promotionIDs (repeat up to 100)']);
  exit;
}
if (count($ids) > 100) {
  $ids = array_slice($ids, 0, 100);
}

// 3) Build WB URL with repeated promotionIDs
$endpointBase = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions/details';
$q = [];
foreach ($ids as $id) {
  // repeated keys: promotionIDs=1&promotionIDs=3
  $q[] = 'promotionIDs=' . rawurlencode((string)$id);
}
$endpoint = $endpointBase . '?' . implode('&', $q);

// 4) Call WB (Authorization header as requested)
// If your account requires X-Api-Key, change the header line accordingly.
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_HTTPHEADER => [
    'Authorization: ' . $token, // change to 'X-Api-Key: ' . $token if needed
    'Accept: application/json',
  ],
]);

$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
  http_response_code(500);
  echo json_encode(['error' => 'Curl error', 'detail' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
if ($http < 200 || $http >= 300) {
  http_response_code($http);
  echo json_encode(['error' => 'WB API error', 'status' => $http, 'detail' => $resp], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// 5) Parse JSON
$raw = json_decode($resp, true);
if ($raw === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(502);
  echo json_encode([
    'error' => 'JSON decode error',
    'json_error' => json_last_error_msg(),
    'raw_preview' => mb_substr($resp, 0, 400, 'UTF-8'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// 6) Extract data.promotions
$data = (isset($raw['data']) && is_array($raw['data'])) ? $raw['data'] : $raw;
$promos = (isset($data['promotions']) && is_array($data['promotions'])) ? $data['promotions'] : [];

// 7) Normalize output for frontend
function isoOrNull($v) {
  if (!is_string($v) || $v === '') return null;
  $t = strtotime($v);
  if ($t === false) return null;
  return gmdate('c', $t);
}

$nowTs = time();
$out = [];
foreach ($promos as $p) {
  if (!is_array($p)) continue;

  $start = isoOrNull($p['startDateTime'] ?? null);
  $end   = isoOrNull($p['endDateTime'] ?? null);

  $active = null;
  if ($start !== null) {
    $s = strtotime($start);
    $e = $end ? strtotime($end) : null;
    if ($s !== false) $active = ($nowTs >= $s) && ($e === null || $nowTs <= $e);
  }

  $out[] = [
    'id'                        => isset($p['id']) ? (int)$p['id'] : null,
    'name'                      => isset($p['name']) ? (string)$p['name'] : null,
    'description'               => isset($p['description']) ? (string)$p['description'] : null,
    'advantages'                => isset($p['advantages']) && is_array($p['advantages']) ? array_values($p['advantages']) : [],
    'startDate'                 => $start,
    'endDate'                   => $end,
    'active'                    => $active,
    'inPromoActionLeftovers'    => isset($p['inPromoActionLeftovers']) ? (int)$p['inPromoActionLeftovers'] : null,
    'inPromoActionTotal'        => isset($p['inPromoActionTotal']) ? (int)$p['inPromoActionTotal'] : null,
    'notInPromoActionLeftovers' => isset($p['notInPromoActionLeftovers']) ? (int)$p['notInPromoActionLeftovers'] : null,
    'notInPromoActionTotal'     => isset($p['notInPromoActionTotal']) ? (int)$p['notInPromoActionTotal'] : null,
    'participationPercentage'   => isset($p['participationPercentage']) ? (int)$p['participationPercentage'] : null,
    'type'                      => isset($p['type']) ? (string)$p['type'] : null, // "regular" | "auto"
    'exceptionProductsCount'    => isset($p['exceptionProductsCount']) ? (int)$p['exceptionProductsCount'] : null,
    'ranging'                   => isset($p['ranging']) && is_array($p['ranging']) ? array_values($p['ranging']) : [],
  ];
}

// Optional: sort by startDate asc then name
usort($out, static function ($a, $b) {
  $as = $a['startDate'] ?? '';
  $bs = $b['startDate'] ?? '';
  $cmp = strcmp($as, $bs);
  if ($cmp !== 0) return $cmp;
  return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

echo json_encode(array_values($out), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);