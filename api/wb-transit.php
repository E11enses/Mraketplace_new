<?php
// api/wb-transit.php — SUPPLIES API
// Возвращает нормализованный список транзитных направлений FBW.

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
  // Если вернёт 401 — замените на 'X-Api-Key: ' . $token
  'Authorization: ' . $token,
  'Accept: application/json',
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

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
// Некоторые ответы могут быть завернуты
if (isset($raw['data']) && is_array($raw['data'])) $raw = $raw['data'];
elseif (isset($raw['result']) && is_array($raw['result'])) $raw = $raw['result'];
elseif (isset($raw['rows']) && is_array($raw['rows'])) $raw = $raw['rows'];

// Нормализация под поля из документации:
// transitWarehouseName, destinationWarehouseName, activeFrom, boxTariff, palletTariff
$items = [];
foreach ($raw as $r) {
  // Идентификаторы складов в этой ручке могут отсутствовать — есть имена.
  // Сохраним имена, а ID оставим пустыми, если их нет.
  $fromName = isset($r['transitWarehouseName']) ? (string)$r['transitWarehouseName'] : null;
  $toName   = isset($r['destinationWarehouseName']) ? (string)$r['destinationWarehouseName'] : null;

  // Иногда API может возвращать ID — поддержим возможные варианты:
  $fromId = null;
  if (isset($r['fromWarehouseId'])) $fromId = (string)$r['fromWarehouseId'];
  elseif (isset($r['fromWarehouseID'])) $fromId = (string)$r['fromWarehouseID'];

  $toId = null;
  if (isset($r['toWarehouseId'])) $toId = (string)$r['toWarehouseId'];
  elseif (isset($r['toWarehouseID'])) $toId = (string)$r['toWarehouseID'];

  // Тарифы: короб и паллета
  $boxTariff = array_key_exists('boxTariff', $r) ? $r['boxTariff'] : null;
  $palletTariff = array_key_exists('palletTariff', $r) ? $r['palletTariff'] : null;

  // Дата начала действия
  $activeFrom = isset($r['activeFrom']) ? (string)$r['activeFrom'] : null;

  // Стандартные поля нашего фронта: tariff/currency/leadTimeDays/active — здесь не применимо.
  // Заполним:
  // - tariff оставим null, покажем box/pallet в "Прочее"
  // - currency: RUB
  // - leadTimeDays: null
  // - active: true, если activeFrom в прошлом или сегодня (если поле есть), иначе null
  $active = null;
  if ($activeFrom) {
    $ts = strtotime($activeFrom);
    if ($ts !== false) {
      $active = (time() >= $ts);
    }
  }

  $extra = [];
  if ($boxTariff !== null) $extra[] = 'box: ' . $boxTariff;
  if ($palletTariff !== null) $extra[] = 'pallet: ' . $palletTariff;

  $items[] = [
    'fromWarehouseId' => $fromId,          // может быть null
    'toWarehouseId'   => $toId,            // может быть null
    'fromWarehouseName' => $fromName,
    'toWarehouseName'   => $toName,
    'tariff' => null,
    'currency' => 'RUB',
    'leadTimeDays' => null,
    'active' => $active,
    'updatedAt' => $activeFrom,            // используем как "с" дата
    'serviceType' => null,
    'weightLimitKg' => null,
    'comment' => null,
    'extra' => implode(', ', $extra),
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