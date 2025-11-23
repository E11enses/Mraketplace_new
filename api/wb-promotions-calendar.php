<?php
// api/wb-promotions-calendar.php
// Fetches Wildberries promotions calendar and returns a normalized list
// suitable for a calendar UI (active flags, date range, names, types, etc.)

declare(strict_types=1);

// Error visibility (optionally disable in prod)
ini_set('display_errors', '1');
error_reporting(E_ALL);

// 1) Get Promotions token: ENV -> file wb_ads_token (plain text, no extra formatting)
$token = getenv('WB_ADS_TOKEN');
if (!$token) {
  $secretFile = __DIR__ . '/wb_ads_token.php';
  if (file_exists($secretFile)) {
    $token = trim((string)file_get_contents($secretFile));
  }
}

// 2) Basic headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if (!$token) {
  http_response_code(500);
  echo json_encode(['error' => 'WB_ADS_TOKEN not configured']);
  exit;
}

// 3) Endpoint (as per docs)
$endpoint = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions';

// 4) Perform request
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_HTTPHEADER => [
    // Most WB promo endpoints accept Authorization with the token
    // If docs state 'X-Api-Key', switch the header below accordingly.
    'Authorization: ' . $token,
    'Accept: application/json',
  ],
]);

$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

// 5) Transport-level errors
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

// 6) Parse JSON safely
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

// 7) Unwrap common containers (defensive)
if (!is_array($raw)) {
  $raw = [];
} elseif (isset($raw['data']) && is_array($raw['data'])) {
  $raw = $raw['data'];
} elseif (isset($raw['result']) && is_array($raw['result'])) {
  $raw = $raw['result'];
} elseif (isset($raw['rows']) && is_array($raw['rows'])) {
  $raw = $raw['rows'];
}

// 8) Utility: parse date safely and create flags
function parseDate(?string $s): ?string {
  if (!$s) return null;
  $ts = strtotime($s);
  if ($ts === false) return null;
  // Return ISO8601 in UTC to be consistent for frontend
  return gmdate('c', $ts);
}

$nowTs = time();

// 9) Normalize for calendar UI
$items = [];
foreach ($raw as $p) {
  if (!is_array($p)) continue;

  // Try common field names from the WB Promotions Calendar API.
  // Adjust keys if your actual payload uses different ones.
  $id          = isset($p['id']) ? (string)$p['id'] : (isset($p['promotionId']) ? (string)$p['promotionId'] : null);
  $name        = isset($p['name']) ? (string)$p['name'] : (isset($p['promotionName']) ? (string)$p['promotionName'] : null);
  $type        = isset($p['type']) ? (string)$p['type'] : (isset($p['promotionType']) ? (string)$p['promotionType'] : null);
  $status      = isset($p['status']) ? (string)$p['status'] : null;
  $region      = isset($p['region']) ? (string)$p['region'] : (isset($p['regionName']) ? (string)$p['regionName'] : null);

  // Date fields (common patterns in such APIs)
  $startRaw    = $p['startDate'] ?? $p['start'] ?? $p['dateFrom'] ?? null;
  $endRaw      = $p['endDate']   ?? $p['end']   ?? $p['dateTo']   ?? null;

  $startIso = parseDate(is_string($startRaw) ? $startRaw : null);
  $endIso   = parseDate(is_string($endRaw) ? $endRaw : null);

  // Active flag: now between start and end (inclusive if end present)
  $active = null;
  if ($startIso !== null) {
    $sTs = strtotime($startIso);
    $eTs = $endIso ? strtotime($endIso) : null;
    if ($sTs !== false) {
      $active = ($nowTs >= $sTs) && ($eTs === null || $nowTs <= $eTs);
    }
  }

  // Optional promo parameters frequently present
  $discount    = isset($p['discount']) ? $p['discount'] : (isset($p['discountPercent']) ? $p['discountPercent'] : null);
  $budget      = isset($p['budget']) ? $p['budget'] : null;
  $link        = isset($p['link']) ? (string)$p['link'] : null;

  $items[] = [
    'id'            => $id,
    'name'          => $name,
    'type'          => $type,
    'status'        => $status,
    'region'        => $region,
    'startDate'     => $startIso,
    'endDate'       => $endIso,
    'active'        => $active,
    'discount'      => is_numeric($discount) ? (0 + $discount) : null,
    'budget'        => is_numeric($budget) ? (0 + $budget) : null,
    'link'          => $link,
    // rawSnapshot gives you an escape hatch for fields not normalized yet
    // Comment this out if you don’t want any passthrough data.
    // 'rawSnapshot'   => $p,
  ];
}

// 10) Sort: active first, then start date asc, then name
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

// 11) Output
echo json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);