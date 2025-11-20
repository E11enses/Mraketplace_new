<?php
// api/wb-transit.php — SUPPLIES API (FBW transit tariffs)
// Возвращает нормализованный список направлений с ценами:
// - palletTariff
// - boxTariffRanges [{from,to,value}], а также boxMinPerLiter/boxMaxPerLiter

declare(strict_types=1);

// Включаем явные ошибки (можете отключить на проде)
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Получаем токен: ENV -> файл wb_token.php (чистый текст)
$token = getenv('WB_SUPPLIES_TOKEN');
if (!$token) {
  $secretFile = __DIR__ . '/wb_token.php';
  if (file_exists($secretFile)) {
    $token = trim((string)file_get_contents($secretFile));
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

// ВАЖНО: правильный хост — supplies-api
$endpoint = 'https://supplies-api.wildberries.ru/api/v1/transit-tariffs';

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_HTTPHEADER => [
    // Если ваш аккаунт требует другой заголовок, поменяйте на 'X-Api-Key: ' . $token
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
  echo json_encode(['error' => 'WB API error', 'status' => $http, 'detail' => $resp], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$raw = json_decode($resp, true);

// Если JSON не распарсился
if ($raw === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(502);
  echo json_encode([
    'error' => 'JSON decode error',
    'json_error' => json_last_error_msg(),
    'raw_preview' => mb_substr($resp, 0, 400, 'UTF-8'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Разворачиваем возможные контейнеры
if (!is_array($raw)) {
  $raw = [];
} elseif (isset($raw['data']) && is_array($raw['data'])) {
  $raw = $raw['data'];
} elseif (isset($raw['result']) && is_array($raw['result'])) {
  $raw = $raw['result'];
} elseif (isset($raw['rows']) && is_array($raw['rows'])) {
  $raw = $raw['rows'];
}

// Утилиты приведения чисел
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
  if (!is_array($r)) continue;

  // Названия складов по документации
  $fromName = isset($r['transitWarehouseName']) ? (string)$r['transitWarehouseName'] : null;
  $toName   = isset($r['destinationWarehouseName']) ? (string)$r['destinationWarehouseName'] : null;

  // Идентификаторы (если присутствуют)
  $fromId = null;
  if (isset($r['fromWarehouseId'])) $fromId = (string)$r['fromWarehouseId'];
  elseif (isset($r['fromWarehouseID'])) $fromId = (string)$r['fromWarehouseID'];

  $toId = null;
  if (isset($r['toWarehouseId'])) $toId = (string)$r['toWarehouseId'];
  elseif (isset($r['toWarehouseID'])) $toId = (string)$r['toWarehouseID'];

  // Дата начала действия
  $activeFrom = isset($r['activeFrom']) ? (string)$r['activeFrom'] : null;
  $active = null;
  if ($activeFrom) {
    $ts = strtotime($activeFrom);
    if ($ts !== false) $active = (time() >= $ts);
  }

  // Паллетный тариф
  $palletTariff = array_key_exists('palletTariff', $r) ? numOrNull($r['palletTariff']) : null;

  // Тарифы за литр (boxTariff: интервалы)
  $boxRanges = [];
  $boxMin = null;
  $boxMax = null;

  if (isset($r['boxTariff']) && is_array($r['boxTariff'])) {
    foreach ($r['boxTariff'] as $t) {
      if (!is_array($t)) continue;

      $fromVol = isset($t['from']) ? (int)$t['from'] : null;
      $toVol   = isset($t['to']) ? (int)$t['to'] : null; // 0 = без верхней границы
      $val     = array_key_exists('value', $t) ? numOrNull($t['value']) : null; // ₽/л

      if ($fromVol !== null && $val !== null) {
        $boxRanges[] = ['from' => $fromVol, 'to' => $toVol, 'value' => $val];
        if ($boxMin === null || $val < $boxMin) $boxMin = $val;
        if ($boxMax === null || $val > $boxMax) $boxMax = $val;
      }
    }

    // Сортировка по началу интервала
    usort($boxRanges, static function ($a, $b) {
      return ($a['from'] <=> $b['from']);
    });
  }

  $items[] = [
    'fromWarehouseId'   => $fromId ?: null,
    'toWarehouseId'     => $toId ?: null,
    'fromWarehouseName' => $fromName ?: null,
    'toWarehouseName'   => $toName ?: null,
    'active'            => $active,
    'updatedAt'         => $activeFrom ?: null,

    // Явные ценовые поля:
    'palletTariff'      => $palletTariff,    // число или null (₽ за паллету)
    'boxTariffRanges'   => $boxRanges,       // массив диапазонов (₽/л)
    'boxMinPerLiter'    => $boxMin,          // минимальная цена за литр
    'boxMaxPerLiter'    => $boxMax,          // максимальная цена за литр
  ];
}

// Сортировка: по имени склада-источника, затем по имени склада-назначения
usort($items, static function ($a, $b) {
  $af = $a['fromWarehouseName'] ?? '';
  $bf = $b['fromWarehouseName'] ?? '';
  $cmp = strcmp($af, $bf);
  if ($cmp !== 0) return $cmp;
  return strcmp($a['toWarehouseName'] ?? '', $b['toWarehouseName'] ?? '');
});

// Возвращаем JSON
echo json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);