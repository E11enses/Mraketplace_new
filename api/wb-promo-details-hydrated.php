<?php
// api/wb-promo-details-hydrated.php
// One-call endpoint:
// 1) Fetches promotions list (ids) for a window
// 2) Fetches details for those ids
// 3) Returns normalized detailed promotions
//
// Optional query params (pass-through to list):
//   startDateTime, endDateTime (RFC3339 UTC), allPromo=true|false, limit, offset
//
// Change only the header line if your account requires X-Api-Key.

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

// 2) Helper: RFC3339 UTC
function rfc3339_utc(int $ts): string {
  return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

// 3) Gather params for list call (defaults: now -> +60 days, allPromo=true)
$now = time();
$startDateTime = isset($_GET['startDateTime']) && $_GET['startDateTime'] !== '' ? (string)$_GET['startDateTime'] : rfc3339_utc($now);
$endDateTime   = isset($_GET['endDateTime'])   && $_GET['endDateTime']   !== '' ? (string)$_GET['endDateTime']   : rfc3339_utc($now + 60 * 86400);
$allPromo = isset($_GET['allPromo'])
  ? (($_GET['allPromo'] === '1' || strtolower((string)$_GET['allPromo']) === 'true') ? 'true' : 'false')
  : 'true';
$limit  = isset($_GET['limit'])  && is_numeric($_GET['limit'])  ? max(1, min(1000, (int)$_GET['limit'])) : 500;
$offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

// 4) Call WB list endpoint directly (to avoid depending on a local file path)
$listQuery = http_build_query([
  'startDateTime' => $startDateTime,
  'endDateTime'   => $endDateTime,
  'allPromo'      => $allPromo,
  'limit'         => $limit,
  'offset'        => $offset,
]);
$listUrl = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions?' . $listQuery;

$ch = curl_init($listUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_HTTPHEADER => [
    'Authorization: ' . $token, // if needed: 'X-Api-Key: ' . $token
    'Accept: application/json',
  ],
]);
$listResp = curl_exec($ch);
$listHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$listErr  = curl_error($ch);
curl_close($ch);

if ($listResp === false) {
  http_response_code(500);
  echo json_encode(['error' => 'Curl error (list)', 'detail' => $listErr], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
if ($listHttp < 200 || $listHttp >= 300) {
  http_response_code($listHttp);
  echo json_encode(['error' => 'WB API error (list)', 'status' => $listHttp, 'detail' => $listResp], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$listJson = json_decode($listResp, true);
if ($listJson === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(502);
  echo json_encode(['error' => 'JSON decode error (list)', 'json_error' => json_last_error_msg()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
$listData = $listJson['data'] ?? $listJson;
$promosList = [];
if (is_array($listData)) {
  if (isset($listData['promotions']) && is_array($listData['promotions'])) {
    $promosList = $listData['promotions'];
  } else {
    $promosList = $listData;
  }
}

// 5) Collect IDs
$ids = [];
foreach ((array)$promosList as $p) {
  if (!is_array($p)) continue;
  $id = $p['id'] ?? ($p['promotionId'] ?? null);
  if ($id === null) continue;
  $ids[] = (int)$id;
}
$ids = array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
if (!$ids) {
  echo json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
// WB limit is 100 IDs; trim if necessary
if (count($ids) > 100) $ids = array_slice($ids, 0, 100);

// 6) Call WB details with repeated promotionIDs
$detailsBase = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions/details';
$qs = [];
foreach ($ids as $id) $qs[] = 'promotionIDs=' . rawurlencode((string)$id);
$detailsUrl = $detailsBase . '?' . implode('&', $qs);

$ch = curl_init($detailsUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_HTTPHEADER => [
    'Authorization: ' . $token, // if needed: 'X-Api-Key: ' . $token
    'Accept: application/json',
  ],
]);
$detResp = curl_exec($ch);
$detHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$detErr  = curl_error($ch);
curl_close($ch);

if ($detResp === false) {
  http_response_code(500);
  echo json_encode(['error' => 'Curl error (details)', 'detail' => $detErr], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
if ($detHttp < 200 || $detHttp >= 300) {
  http_response_code($detHttp);
  echo json_encode(['error' => 'WB API error (details)', 'status' => $detHttp, 'detail' => $detResp], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$detJson = json_decode($detResp, true);
if ($detJson === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(502);
  echo json_encode(['error' => 'JSON decode error (details)', 'json_error' => json_last_error_msg()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$detData = $detJson['data'] ?? $detJson;
$promos = (isset($detData['promotions']) && is_array($detData['promotions'])) ? $detData['promotions'] : [];

// 7) Normalize
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

// Sort by startDate asc, then name
usort($out, static function ($a, $b) {
  $as = $a['startDate'] ?? '';
  $bs = $b['startDate'] ?? '';
  $cmp = strcmp($as, $bs);
  if ($cmp !== 0) return $cmp;
  return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

echo json_encode(array_values($out), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);