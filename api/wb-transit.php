<?php
// api/wb-transit.php — SUPPLIES API
// Возвращает нормализованный список транзитных направлений FBW,
// включая паллетный тариф и тарифы за литр (диапазоны + min/max).

$token = getenv('WB_SUPPLIES_TOKEN');
if (!$token) {
  $secretFile = __DIR__ . '/wb_token.php';
  if (file_exists($secretFile)) {
    $token = trim(file_get_contents($secretFile));
  }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if (!$token) {
  http_response_code(500);
  echo json_encode(['error' => 'WB_SUPPLIES_TOKEN not configured']);
  exit;
}

// ВАЖНО: хост именно supplies-api
$endpoint = 'https://supplies-api.wildberries.ru/api/v1/transit-tariffs';

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  // Если вернёт 401 — поменяйте на 'X-Api-Key: ' . $token
  'Authorization: ' . $token,
  'Accept: application/json',
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);

$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
  http_response_code(500);
  echo json_encode(['error' => 'Curl error', 'detail' => $err]);
  exit;
}
if ($http < 200 || $http >= 300) {
  http_response_code($http);
  echo json_encode(['error' => 'WB API error', 'status' => $http, 'detail' => $resp]);
  exit;
}

$raw = json_decode($resp, true);
if (!is_array($raw)) $raw = [];
if (isset($raw['data']) && is_array($raw['data'])) $raw = $raw['data'];
elseif (isset($raw['result']) && is_array($raw['result'])) $raw = $raw['result'];
elseif (isset($raw['rows']) && is_array($raw['rows'])) $raw = $raw['rows'];

function numOrNull($v) {
  if ($v === null || $v === '') return null;
  if (is_numeric($v)) return 0 + $v;
  if (is_string($v)) {
    $x = str_replace(' ', '', $v);
    $x = str_replace(',', '.', $x);
    if (is_numeric($x)) return 0 + $x;
  }
  return null;
}

$items = [];
foreach ($raw as $r) {
  $fromName = isset($r['transitWarehouseName']) ? (string)$r['transitWarehouseName'] : null;
  $toName   = isset($r['destinationWarehouseName']) ? (string)$r['destinationWarehouseName'] : null;

  $fromId = null;
  if (isset($r['fromWarehouseId'])) $fromId = (string)$r['fromWarehouseId'];
  elseif (isset($r['fromWarehouseID'])) $fromId = (string)$r['fromWarehouseID'];

  $toId = null;
  if (isset($r['toWarehouseId'])) $toId = (string)$r['toWarehouseId'];
  elseif (isset($r['toWarehouseID'])) $toId = (string)$r['toWarehouseID'];

  $activeFrom = isset($r['activeFrom']) ? (string)$r['activeFrom'] : null;
  $active = null;
  if ($activeFrom) {
    $ts = strtotime($activeFrom);
    if ($ts !== false) $active = (time() >= $ts);
  }

  // Паллетный тариф
  $palletTariff = array_key_exists('palletTariff', $r) ? numOrNull($r['palletTariff']) : null;

  // Диапазоны boxTariff (за литр)
  $boxRanges = [];
  $boxMin = null;
  $boxMax = null;
  if (isset($r['boxTariff']) && is_array($r['boxTariff'])) {
    foreach ($r['boxTariff'] as $t) {
      $from = isset($t['from']) ? intval($t['from']) : null;
      $to   = isset($t['to']) ? intval($t['to']) : null; // 0 = без верхней границы
      $val  = numOrNull($t['value']); // ₽/л
      if ($from !== null && $val !== null) {
        $boxRanges[] = ['from' => $from, 'to' => $to, 'value' => $val];
        if ($boxMin === null || $val < $boxMin) $boxMin = $val;
        if ($boxMax === null || $val > $boxMax) $boxMax = $val;
      }
    }
    usort($boxRanges, fn($a, $b) => $a['from'] <=> $b['from']);
  }

  $items[] = [
    'fromWarehouseId'   => $fromId,
    'toWarehouseId'     => $toId,
    'fromWarehouseName' => $fromName,
    'toWarehouseName'   => $toName,
    'active'            => $active,
    'updatedAt'         => $activeFrom,

    // Явные ценовые поля:
    'palletTariff'      => $palletTariff,     // число или null
    'boxTariffRanges'   => $boxRanges,        // массив [{from,to,value}]
    'boxMinPerLiter'    => $boxMin,           // число или null
    'boxMaxPerLiter'    => $boxMax,           // число или null
  ];
}

// Сортировка: по fromName, затем toName
usort($items, function ($a, $b) {
  $af = $a['fromWarehouseName'] ?? '';
  $bf = $b['fromWarehouseName'] ?? '';
  $cmp = strcmp($af, $bf);
  if ($cmp !== 0) return $cmp;
  return strcmp($a['toWarehouseName'] ?? '', $b['toWarehouseName'] ?? '');
});

echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);