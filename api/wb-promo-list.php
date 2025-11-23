<?php
// api/wb-promo-list.php
// Lists promotions within a date window and returns minimal fields including id and name.
// Use this to obtain IDs for the details endpoint.
//
// Example:
//   /api/wb-promo-list.php                 -> defaults (now .. +60d, allPromo=true)
//   /api/wb-promo-list.php?allPromo=false  -> only available-to-participate
//   /api/wb-promo-list.php?startDateTime=2025-11-23T00:00:00Z&endDateTime=2026-01-23T23:59:59Z
//
// Token loading: ENV WB_ADS_TOKEN, else file api/wb_ads_token.php (plain text).
// Header: Authorization: <token> (switch to X-Api-Key if your account requires it).

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

// 2) Params and defaults
function rfc3339_utc(int $ts): string {
  return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

$now = time();
// Default window: now -> +60 days
$startDateTime = isset($_GET['startDateTime']) && $_GET['startDateTime'] !== ''
  ? (string)$_GET['startDateTime']
  : rfc3339_utc($now);

$endDateTime = isset($_GET['endDateTime']) && $_GET['endDateTime'] !== ''
  ? (string)$_GET['endDateTime']
  : rfc3339_utc($now + 60 * 86400);

// By default list all (easier to discover IDs)
$allPromo = isset($_GET['allPromo'])
  ? (($_GET['allPromo'] === '1' || strtolower((string)$_GET['allPromo']) === 'true') ? 'true' : 'false')
  : 'true';

$limit  = isset($_GET['limit'])  && is_numeric($_GET['limit'])  ? max(1, min(1000, (int)$_GET['limit'])) : 500;
$offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

// 3) Build WB request
$q = [
  'startDateTime' => $startDateTime,
  'endDateTime'   => $endDateTime,
  'allPromo'      => $allPromo,
  'limit'         => $limit,
  'offset'        => $offset,
];

$endpoint = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions?' . http_build_query($q);

// 4) Call WB (Authorization header as requested)
// If your tenant requires X-Api-Key, replace the header line below accordingly.
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

// 5) Parse response
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

// WB format: usually { data: { promotions: [...] } }
$data = $raw['data'] ?? $raw;
$list = [];
if (is_array($data)) {
  if (isset($data['promotions']) && is_array($data['promotions'])) {
    $list = $data['promotions'];
  } else {
    // fallback if API returns array directly
    $list = $data;
  }
}

// 6) Minimal normalized output with IDs
function isoOrNull($v) {
  if (!is_string($v) || $v === '') return null;
  $t = strtotime($v);
  if ($t === false) return null;
  return gmdate('c', $t);
}

$out = [];
foreach ((array)$list as $p) {
  if (!is_array($p)) continue;

  $idRaw = $p['id'] ?? ($p['promotionId'] ?? null);
  if ($idRaw === null) continue;

  $out[] = [
    'id'   => is_numeric($idRaw) ? (int)$idRaw : $idRaw,
    'name' => isset($p['name']) ? (string)$p['name'] : ($p['promotionName'] ?? null),
    'startDateTime' => isoOrNull($p['startDateTime'] ?? ($p['startDate'] ?? $p['dateFrom'] ?? null)),
    'endDateTime'   => isoOrNull($p['endDateTime']   ?? ($p['endDate']   ?? $p['dateTo']   ?? null)),
  ];
}

// Sort by startDateTime asc, then name
usort($out, static function ($a, $b) {
  $as = $a['startDateTime'] ?? '';
  $bs = $b['startDateTime'] ?? '';
  $cmp = strcmp($as, $bs);
  if ($cmp !== 0) return $cmp;
  return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

echo json_encode(array_values($out), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);