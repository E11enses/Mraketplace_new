<?php
// api/wb-promotions-calendar.php
// Fetches Wildberries promotions calendar and returns a normalized list
// suitable for a calendar UI (active flags, date range, names, types, etc.)

declare(strict_types=1);

// Error visibility (optionally disable on prod)
ini_set('display_errors', '1');
error_reporting(E_ALL);

// 1) Get Promotions token: ENV -> file wb_ads_token.php (plain text, no extra formatting)
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

// Helpers
function rfc3339_utc(int $ts): string {
  return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

// Required query params per WB docs
// If not provided via GET, use sane defaults: last 7 days to next 60 days
$now = time();
$defaultStart = rfc3339_utc($now - 7 * 86400);
$defaultEnd   = rfc3339_utc($now + 60 * 86400);

$startDateTime = isset($_GET['startDateTime']) && $_GET['startDateTime'] !== ''
  ? (string)$_GET['startDateTime']
  : $defaultStart;

$endDateTime = isset($_GET['endDateTime']) && $_GET['endDateTime'] !== ''
  ? (string)$_GET['endDateTime']
  : $defaultEnd;

// allPromo: false by default (only available-to-participate)
$allPromo = isset($_GET['allPromo'])
  ? (($_GET['allPromo'] === '1' || strtolower((string)$_GET['allPromo']) === 'true') ? 'true' : 'false')
  : 'false';

// Pagination (optional)
$limit  = isset($_GET['limit'])  && is_numeric($_GET['limit'])  ? max(1, min(1000, (int)$_GET['limit'])) : 500;
$offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

// Endpoint + query
$endpointBase = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions';
$q = [
  'startDateTime' => $startDateTime,
  'endDateTime'   => $endDateTime,
  'allPromo'      => $allPromo,
  'limit'         => $limit,
  'offset'        => $offset,
];
$endpoint = $endpointBase . '?' . http_build_query($q);

// Request (Authorization header as per your preference)
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_HTTPHEADER => [
    'Authorization: ' . $token,
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
  $payload = [
    'error' => 'WB API error',
    'status' => $http,
    'detail' => $resp,
  ];
  if ($http === 401) {
    $payload['hint'] = 'Unauthorized: token may not have Promotions scope or header may not be accepted.';
  } elseif ($http === 400) {
    $payload['hint'] = 'Bad Request: check startDateTime/endDateTime/allPromo/limit/offset format.';
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Parse JSON
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

// Unwrap common containers if present
if (!is_array($raw)) {
  $raw = [];
} elseif (isset($raw['data']) && is_array($raw['data'])) {
  $raw = $raw['data'];
} elseif (isset($raw['result']) && is_array($raw['result'])) {
  $raw = $raw['result'];
} elseif (isset($raw['rows']) && is_array($raw['rows'])) {
  $raw = $raw['rows'];
}

// Normalization
function parseIso(?string $s): ?string {
  if (!$s) return null;
  $ts = strtotime($s);
  if ($ts === false) return null;
  return gmdate('c', $ts);
}

$nowTs = time();
$items = [];

foreach ($raw as $p) {
  if (!is_array($p)) continue;

  // Map likely fields (adjust if WB returns different keys)
  $id    = isset($p['id']) ? (string)$p['id'] : (isset($p['promotionId']) ? (string)$p['promotionId'] : null);
  $name  = isset($p['name']) ? (string)$p['name'] : (isset($p['promotionName']) ? (string)$p['promotionName'] : null);
  $type  = isset($p['type']) ? (string)$p['type'] : (isset($p['promotionType']) ? (string)$p['promotionType'] : null);
  $status= isset($p['status']) ? (string)$p['status'] : null;
  $region= isset($p['region']) ? (string)$p['region'] : (isset($p['regionName']) ? (string)$p['regionName'] : null);

  // Dates as provided by API might already be RFC3339
  $startIso = parseIso(is_string($p['startDateTime'] ?? null) ? $p['startDateTime'] : ($p['startDate'] ?? $p['dateFrom'] ?? null));
  $endIso   = parseIso(is_string($p['endDateTime'] ?? null) ? $p['endDateTime'] : ($p['endDate'] ?? $p['dateTo'] ?? null));

  $active = null;
  if ($startIso !== null) {
    $sTs = strtotime($startIso);
    $eTs = $endIso ? strtotime($endIso) : null;
    if ($sTs !== false) {
      $active = ($nowTs >= $sTs) && ($eTs === null || $nowTs <= $eTs);
    }
  }

  $discount = $p['discount'] ?? ($p['discountPercent'] ?? null);
  $budget   = $p['budget'] ?? null;
  $link     = isset($p['link']) ? (string)$p['link'] : null;

  $items[] = [
    'id'        => $id,
    'name'      => $name,
    'type'      => $type,
    'status'    => $status,
    'region'    => $region,
    'startDate' => $startIso,
    'endDate'   => $endIso,
    'active'    => $active,
    'discount'  => is_numeric($discount) ? (0 + $discount) : null,
    'budget'    => is_numeric($budget) ? (0 + $budget) : null,
    'link'      => $link,
  ];
}

// Sort: active first, then start date asc, then name
usort($items, static function ($a, $b) {
  $aAct = $a['active'] ? 1 : 0;
  $bAct = $b['active'] ? 1 : 0;
  if ($aAct !== $bAct) return $bAct - $aAct;
  $as = $a['startDate'] ?? '';
  $bs = $b['startDate'] ?? '';
  $cmp = strcmp($as, $bs);
  if ($cmp !== 0) return $cmp;
  return strcmp($a['name'] ?? '', $b['name'] ?? '');
});

echo json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);