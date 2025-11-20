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
    // Если получите 401 — попробуйте 'X-Api-Key: ' . $token
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
  echo json_encode([
    'error' => 'JSON decode error',
    'json_error' => json_last_error_msg(),
    'raw_preview' => mb_substr($resp, 0, 500, 'UTF-8'),
  ]);
  exit;
}

// Универсальная распаковка
$rows = [];
if (isset($data['data']['items']) && is_array($data['data']['items'])) {
  $rows = $data['data']['items'];
} elseif (isset($data['data']) && is_array($data['data'])) {
  $rows = $data['data'];
} elseif (isset($data['result']) && is_array($data['result'])) {
  $rows = $data['result'];
} elseif (is_array($data)) {
  // если это уже массив объектов — используем его
  $rows = $data;
}

// Возвращаем как есть (без нормализации), чтобы фронт увидел реальные поля
echo json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);