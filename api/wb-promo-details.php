<?php
// api/wb-promo-details.php
// 1) Lists promotions for a rolling 60-day window (now -> +60d, allPromo=true)
// 2) Fetches detailed info for those IDs
// 3) Returns normalized array of detailed promotions
// С кэшированием: оба запроса к WB делаются раз в сутки,
// результат хранится в cache/wb-promo-details.json

declare(strict_types=1);

// ── Настройки кэша ──────────────────────────────────────────
$cacheFile = __DIR__ . '/cache/wb-promo-details.json';
$cacheTTL  = 86400; // секунд = 24 часа
// ────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

// ── Отдаём кэш, если он свежий ───────────────────────────────
if (file_exists($cacheFile)) {
  $age = time() - filemtime($cacheFile);
  if ($age < $cacheTTL) {
    $cached = file_get_contents($cacheFile);
    if ($cached !== false && $cached !== '') {
      echo $cached;
      exit;
    }
  }
}
// ─────────────────────────────────────────────────────────────

// ── Токен ────────────────────────────────────────────────────
$token = getenv('WB_ADS_TOKEN');
if (!$token) {
  $secretFile = __DIR__ . '/wb_ads_token.php';
  if (file_exists($secretFile)) {
    $token = trim((string)file_get_contents($secretFile));
  }
}

if (!$token) {
  http_response_code(500);
  echo json_encode(['error' => 'WB_ADS_TOKEN not configured'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// ── Вспомогательные функции ──────────────────────────────────
function rfc3339_utc(int $ts): string { return gmdate('Y-m-d\TH:i:s\Z', $ts); }
function iso_or_null($v) {
  if (!is_string($v) || $v === '') return null;
  $t = strtotime($v);
  if ($t === false) return null;
  return gmdate('c', $t);
}

$HEADER_NAME = 'Authorization';

// ── Шаг 1: список акций ──────────────────────────────────────
// При кэшировании GET-параметры с фронтенда игнорируем —
// всегда берём окно now -> +60d, allPromo=true
$now = time();
$listQS = http_build_query([
  'startDateTime' => rfc3339_utc($now),
  'endDateTime'   => rfc3339_utc($now + 60 * 86400),
  'allPromo'      => 'true',
  'limit'         => 500,
  'offset'        => 0,
]);
$listURL = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions?' . $listQS;

$ch = curl_init($listURL);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT        => 30,
  CURLOPT_HTTPHEADER     => [$HEADER_NAME . ': ' . $token, 'Accept: application/json'],
]);
$listBody = curl_exec($ch);
$listCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$listErr  = curl_error($ch);
curl_close($ch);

if ($listBody === false) {
  if (file_exists($cacheFile)) { $s = file_get_contents($cacheFile); if ($s) { echo $s; exit; } }
  http_response_code(500);
  echo json_encode(['error' => 'Curl error (list)', 'detail' => $listErr], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
if ($listCode < 200 || $listCode >= 300) {
  if (file_exists($cacheFile)) { $s = file_get_contents($cacheFile); if ($s) { echo $s; exit; } }
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

$listData  = $listJson['data'] ?? $listJson;
$promosList = [];
if (is_array($listData)) {
  if (isset($listData['promotions']) && is_array($listData['promotions'])) $promosList = $listData['promotions'];
  else $promosList = $listData;
}

// Собираем ID (макс. 100)
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
  $empty = json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  file_put_contents($cacheFile, $empty, LOCK_EX);
  echo $empty;
  exit;
}
if (count($ids) > 100) $ids = array_slice($ids, 0, 100);

// ── Шаг 2: детали акций ──────────────────────────────────────
$detailsBase = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions/details';
$qs = [];
foreach ($ids as $i) $qs[] = 'promotionIDs=' . rawurlencode((string)$i);
$detailsURL = $detailsBase . '?' . implode('&', $qs);

$ch = curl_init($detailsURL);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT        => 30,
  CURLOPT_HTTPHEADER     => [$HEADER_NAME . ': ' . $token, 'Accept: application/json'],
]);
$detBody = curl_exec($ch);
$detCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$detErr  = curl_error($ch);
curl_close($ch);

if ($detBody === false) {
  if (file_exists($cacheFile)) { $s = file_get_contents($cacheFile); if ($s) { echo $s; exit; } }
  http_response_code(500);
  echo json_encode(['error' => 'Curl error (details)', 'detail' => $detErr], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
if ($detCode < 200 || $detCode >= 300) {
  if (file_exists($cacheFile)) { $s = file_get_contents($cacheFile); if ($s) { echo $s; exit; } }
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

// ── Нормализация ─────────────────────────────────────────────
$detData = $detJson['data'] ?? $detJson;
$promos  = (isset($detData['promotions']) && is_array($detData['promotions'])) ? $detData['promotions'] : [];

$nowTs = time();
$out = [];
foreach ($promos as $p) {
  if (!is_array($p)) continue;

  $start  = iso_or_null($p['startDateTime'] ?? null);
  $end    = iso_or_null($p['endDateTime']   ?? null);
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
    'inPromoActionLeftovers'    => isset($p['inPromoActionLeftovers'])    ? (int)$p['inPromoActionLeftovers']    : null,
    'inPromoActionTotal'        => isset($p['inPromoActionTotal'])        ? (int)$p['inPromoActionTotal']        : null,
    'notInPromoActionLeftovers' => isset($p['notInPromoActionLeftovers']) ? (int)$p['notInPromoActionLeftovers'] : null,
    'notInPromoActionTotal'     => isset($p['notInPromoActionTotal'])     ? (int)$p['notInPromoActionTotal']     : null,
    'participationPercentage'   => isset($p['participationPercentage'])   ? (int)$p['participationPercentage']   : null,
    'type'                      => isset($p['type']) ? (string)$p['type'] : null,
    'exceptionProductsCount'    => isset($p['exceptionProductsCount'])    ? (int)$p['exceptionProductsCount']    : null,
    'ranging'                   => isset($p['ranging']) && is_array($p['ranging']) ? array_values($p['ranging']) : [],
  ];
}

usort($out, static function ($a, $b) {
  $cmp = strcmp((string)($a['startDate'] ?? ''), (string)($b['startDate'] ?? ''));
  return $cmp !== 0 ? $cmp : strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

$json = json_encode(array_values($out), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// ── Сохраняем в кэш ──────────────────────────────────────────
$cacheDir = dirname($cacheFile);
if (!is_dir($cacheDir)) {
  mkdir($cacheDir, 0755, true);
}
file_put_contents($cacheFile, $json, LOCK_EX);
// ─────────────────────────────────────────────────────────────

echo $json;