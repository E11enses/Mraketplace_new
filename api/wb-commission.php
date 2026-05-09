<?php
// api/wb-commission.php — COMMON API (WB tariffs: commission / KGVP by subject)
// С кэшированием: данные запрашиваются у WB раз в сутки,
// хранятся в файле cache/wb-commission.json
declare(strict_types=1);

// ── Настройки кэша ──────────────────────────────────────────
$cacheFile = __DIR__ . '/cache/wb-commission.json';
$cacheTTL  = 86400; // секунд = 24 часа
// ────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

// ── Отдаём кэш, если он свежий ───────────────────────────────
// Комиссия не зависит от GET-параметров запроса с фронтенда,
// поэтому кэшируем один раз для всех.
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
$token = getenv('WB_SUPPLIES_TOKEN');
if (!$token) {
  $secretFile = __DIR__ . '/wb_token.php';
  if (file_exists($secretFile)) {
    $token = trim((string)file_get_contents($secretFile));
  }
}

if (!$token) {
  http_response_code(500);
  echo json_encode(['error' => 'WB_SUPPLIES_TOKEN not configured']);
  exit;
}

// ── Запрос к WB API ──────────────────────────────────────────
$endpoint = 'https://common-api.wildberries.ru/api/v1/tariffs/commission';
if (!empty($_GET)) {
  $endpoint .= '?' . http_build_query($_GET);
}

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT        => 25,
  CURLOPT_HTTPHEADER     => [
    'Authorization: ' . $token,
    'Accept: application/json',
  ],
]);

$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
  if (file_exists($cacheFile)) {
    $stale = file_get_contents($cacheFile);
    if ($stale) { echo $stale; exit; }
  }
  http_response_code(500);
  echo json_encode(['error' => 'Curl error', 'detail' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if ($http < 200 || $http >= 300) {
  if (file_exists($cacheFile)) {
    $stale = file_get_contents($cacheFile);
    if ($stale) { echo $stale; exit; }
  }
  http_response_code($http);
  echo json_encode(['error' => 'WB API error', 'status' => $http, 'detail' => $resp], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$data = json_decode($resp, true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(502);
  echo json_encode([
    'error'       => 'JSON decode error',
    'json_error'  => json_last_error_msg(),
    'raw_preview' => mb_substr($resp, 0, 800, 'UTF-8'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// ── Нормализация ─────────────────────────────────────────────
function findArrayLayer($x) {
  if (!is_array($x)) return null;
  if (array_keys($x) === range(0, count($x) - 1)) return $x;
  foreach (['data','result','items','rows','commissions','tariffs'] as $k) {
    if (isset($x[$k])) {
      $res = findArrayLayer($x[$k]);
      if ($res !== null) return $res;
    }
  }
  foreach ($x as $v) {
    if (is_array($v)) {
      $res = findArrayLayer($v);
      if ($res !== null) return $res;
    }
  }
  return null;
}

$rows = null;
if (is_array($data) && array_keys($data) === range(0, count($data) - 1)) {
  $rows = $data;
} else {
  $rows = findArrayLayer($data);
}

if (!is_array($rows)) {
  echo json_encode(['debug' => 'no-array-layer', 'preview' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function numOrNull($v) {
  if ($v === null || $v === '') return null;
  if (is_numeric($v)) return 0 + $v;
  if (is_string($v)) {
    $x = str_replace([' ', ','], ['', '.'], $v);
    if (is_numeric($x)) return 0 + $x;
  }
  return null;
}

$out = [];
foreach ($rows as $r) {
  if (!is_array($r)) continue;
  $out[] = [
    'subjectID'           => $r['subjectID']            ?? null,
    'subjectName'         => $r['subjectName']          ?? null,
    'parentID'            => $r['parentID']             ?? null,
    'parentName'          => $r['parentName']           ?? null,
    'kgvpSupplier'        => numOrNull($r['kgvpSupplier']        ?? null),
    'kgvpMarketplace'     => numOrNull($r['kgvpMarketplace']     ?? null),
    'kgvpPickup'          => numOrNull($r['kgvpPickup']          ?? null),
    'kgvpBooking'         => numOrNull($r['kgvpBooking']         ?? null),
    'kgvpSupplierExpress' => numOrNull($r['kgvpSupplierExpress'] ?? null),
    'paidStorageKgvp'     => numOrNull($r['paidStorageKgvp']     ?? null),
  ];
}

if (empty($out)) {
  echo json_encode([
    'debug'        => 'normalized-empty',
    'rows_count'   => count($rows),
    'rows_preview' => array_slice($rows, 0, 2),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

usort($out, function ($a, $b) {
  $pa = $a['parentName'] ?? '';
  $pb = $b['parentName'] ?? '';
  if ($pa !== $pb) return strcmp($pa, $pb);
  return strcmp($a['subjectName'] ?? '', $b['subjectName'] ?? '');
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