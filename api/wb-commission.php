<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

$token = getenv('WB_SUPPLIES_TOKEN');
if (!$token) {
  $secretFile = __DIR__ . '/wb_token.php';
  if (file_exists($secretFile)) $token = trim((string)file_get_contents($secretFile));
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if (!$token) {
  http_response_code(500);
  echo json_encode(['error' => 'WB_SUPPLIES_TOKEN not configured']);
  exit;
}

$endpoint = 'https://common-api.wildberries.ru/api/v1/tariffs/commission';
if (!empty($_GET)) $endpoint .= '?' . http_build_query($_GET);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_HTTPHEADER => [
    // Оставляем ваш рабочий вариант авторизации
    'Authorization: ' . $token,
    'Accept: application/json',
  ],
]);
$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
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

$data = json_decode($resp, true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(502);
  echo json_encode(['error' => 'JSON decode error', 'json_error' => json_last_error_msg(), 'raw_preview' => mb_substr($resp, 0, 800, 'UTF-8')]);
  exit;
}

// 1) Если это сразу массив — вернём как есть
if (is_array($data) && array_keys($data) === range(0, count($data)-1)) {
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// 2) Популярные контейнеры
$keys = ['items','data','result','rows','tariffs','commissions'];
$rows = null;
foreach ($keys as $k) {
  if (isset($data[$k]) && is_array($data[$k])) {
    $rows = $data[$k];
    break;
  }
}

// 3) Если rows — это не массив строк, попробуем найти первый вложенный массив
if (!is_array($rows)) {
  foreach ($data as $v) {
    if (is_array($v) && array_keys($v) === range(0, count($v)-1)) {
      $rows = $v;
      break;
    }
  }
}

// 4) Если по‑прежнему не нашли — вернём диагностику, чтобы увидеть реальную структуру
if (!is_array($rows)) {
  echo json_encode(['debug' => 'no_rows_detected', 'root_keys' => array_keys((array)$data), 'preview' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// 5) Если нашли массив, но элементы внутри снова объекты с массивами — покажем первый элемент полностью
if (!empty($rows) && is_array($rows[0]) && count($rows) === 1) {
  // некоторые ручки возвращают [ { commissions: [ ... ] } ]
  $inner = null;
  foreach ($keys as $k) {
    if (isset($rows[0][$k]) && is_array($rows[0][$k])) { $inner = $rows[0][$k]; break; }
  }
  if (is_array($inner)) {
    echo json_encode($inner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

// 6) Возвращаем найденные строки как есть — фронт должен их увидеть
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);