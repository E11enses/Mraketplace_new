<?php
// api/wb-promo-details.php
// Final production endpoint:
// 1) Lists promotions for a rolling 60-day window (now -> +60d, allPromo=true)
// 2) Fetches detailed info for those IDs
// 3) Returns normalized array of detailed promotions

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Load token: ENV -> file api/wb_ads_token.php
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
  echo json_encode(['error' => 'WB_ADS_TOKEN not configured'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function rfc3339_utc(int $ts): string { return gmdate('Y-m-d\TH:i:s\Z', $ts); }
function iso_or_null($v) {
  if (!is_string($v) || $v === '') return null;
  $t = strtotime($v);
  if ($t === false) return null;
  return gmdate('c', $t);
}

// Defaults: window now -> +60 days, allPromo=true
$now = time();
$startDateTime = $_GET['startDateTime'] ?? rfc3339_utc($now);
$endDateTime   = $_GET['endDateTime']   ?? rfc3339_utc($now + 60 * 86400);
$allPromo      = isset($_GET['allPromo'])
  ? (($_GET['allPromo'] === '1' || strtolower((string)$_GET['allPromo']) === 'true') ? 'true' : 'false')
  : 'true';
$limit  = isset($_GET['limit'])  && is_numeric($_GET['limit'])  ? max(1, min(1000, (int)$_GET['limit'])) : 500;
$offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

// WB header type: Authorization (change to 'X-Api-Key' if your account requires it)
$HEADER_NAME = 'Authorization';

// Step 1: list promotions
$listQS = http_build_query([
  'startDateTime' => $startDateTime,
  'endDateTime'   => $endDateTime,
  'allPromo'      => $allPromo,
  'limit'         => $limit,
  'offset'        => $offset,
]);
$listURL = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions?' . $listQS;

$ch = curl_init($listURL);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_HTTPHEADER => [
    $HEADER_NAME . ': ' . $token,
    'Accept: application/json',
  ],
]);
$listBody = curl_exec($ch);
$listCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$listErr  = curl_error($ch);
curl_close($ch);

if ($listBody === false) {
  http_response_code(500);
  echo json_encode(['error' => 'Curl error (list)', 'detail' => $listErr], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
if ($listCode < 200 || $listCode >= 300) {
  http_response_code($listCode);
  echo json_encode(['error' => 'WB API error (list)', 'status' => $listCode, 'detail' => $listBody], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$listJson = json_decode($listBody, true);
if ($listJson === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(502);
  echo json_encode(['error' => 'JSON decode error (list)', 'json_error' => json_last_error_msg()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$listData = $listJson['data'] ?? $listJson;
$promosList = [];
if (is_array($listData)) {
  if (isset($listData['promotions']) && is_array($listData['promotions'])) $promosList = $listData['promotions'];
  else $promosList = $listData;
}

// Collect IDs (max 100)
$ids = [];
foreach ((array)$promosList as $p) {
  if (!is_array($p)) continue;
  $id = $p['id'] ?? ($p['promotionId'] ?? null);
  if ($id === null) continue;
  $i = (int)$id;
  if ($i > 0) $ids[] = $i;
}
$ids = array_values(array_unique($ids));
if (!$ids) {
  echo json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
if (count($ids) > 100) $ids = array_slice($ids, 0, 100);

// Step 2: details for these IDs
$detailsBase = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions/details';
$qs = [];
foreach ($ids as $i) $qs[] = 'promotionIDs=' . rawurlencode((string)$i);
$detailsURL = $detailsBase . '?' . implode('&', $qs);

$ch = curl_init($detailsURL);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_HTTPHEADER => [
    $HEADER_NAME . ': ' . $token,
    'Accept: application/json',
  ],
]);
$detBody = curl_exec($ch);
$detCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$detErr  = curl_error($ch);
curl_close($ch);

if ($detBody === false) {
  http_response_code(500);
  echo json_encode(['error' => 'Curl error (details)', 'detail' => $detErr], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
if ($detCode < 200 || $detCode >= 300) {
  http_response_code($detCode);
  echo json_encode(['error' => 'WB API error (details)', 'status' => $detCode, 'detail' => $detBody], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$detJson = json_decode($detBody, true);
if ($detJson === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(502);
  echo json_encode(['error' => 'JSON decode error (details)', 'json_error' => json_last_error_msg()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$detData = $detJson['data'] ?? $detJson;
$promos = (isset($detData['promotions']) && is_array($detData['promotions'])) ? $detData['promotions'] : [];

$nowTs = time();
$out = [];
foreach ($promos as $p) {
  if (!is_array($p)) continue;

  $start = iso_or_null($p['startDateTime'] ?? null);
  $end   = iso_or_null($p['endDateTime'] ?? null);
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