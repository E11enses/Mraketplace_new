<?php
// api/wb-commission.php — COMMON API (WB tariffs: commission)

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

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

$endpoint = 'https://common-api.wildberries.ru/api/v1/tariffs/commission';
if (!empty($_GET)) {
  $endpoint .= '?' . http_build_query($_GET);
}

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_HTTPHEADER => [
    // Если получите 401 — замените эту строку на: 'X-Api-Key: ' . $token,
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

$data = json_decode($resp, true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(502);
  echo json_encode([
    'error' => 'JSON decode error',
    'json_error' => json_last_error_msg(),
    'raw_preview' => mb_substr($resp, 0, 800, 'UTF-8'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Универсальная распаковка: находит первый массив объектов глубоко в ответе
function findArrayLayer($x) {
  if (is_array($x)) {
    // Если это нумерованный массив объектов — вернуть
    $isNumericKeys = array_keys($x) === range(0, count($x) - 1);
    if ($isNumericKeys && (!empty($x) ? is_array($x[0]) : true)) {
      return $x;
    }
    // Типичные ключи контейнеров
    $keys = ['items', 'data', 'result', 'rows', 'tariffs', 'commissions'];
    foreach ($keys as $k) {
      if (isset($x[$k])) {
        $res = findArrayLayer($x[$k]);
        if ($res !== null) return $res;
      }
    }
    // Иначе обойти все поля
    foreach ($x as $v) {
      if (is_array($v)) {
        $res = findArrayLayer($v);
        if ($res !== null) return $res;
      }
    }
  }
  return null;
}

$rows = findArrayLayer($data);
if ($rows === null) {
  // Если прилетел одиночный объект — обернуть в массив
  if (is_array($data) && !empty($data) && array_keys($data) !== range(0, count($data) - 1)) {
    $rows = [$data];
  } else {
    $rows = [];
  }
}

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

// Нормализация в единый формат
$out = [];
foreach ($rows as $r) {
  if (!is_array($r)) continue;

  $out[] = [
    'subject'            => $r['subjectName'] ?? $r['subject'] ?? null,
    'category'           => $r['categoryName'] ?? $r['tnvedName'] ?? $r['groupName'] ?? null,
    'tnved'              => $r['tnved'] ?? $r['tnvedCode'] ?? null,
    'commissionPercent'  => numOrNull($r['commission'] ?? $r['percent'] ?? $r['commissionPercent'] ?? null), // %
    'minCommission'      => numOrNull($r['minCommission'] ?? $r['min'] ?? null), // ₽
    'calcType'           => $r['calcType'] ?? $r['type'] ?? null,
    'region'             => $r['region'] ?? $r['country'] ?? null,
    'brand'              => $r['brand'] ?? null,
    'nmId'               => $r['nmId'] ?? $r['nm'] ?? null,
    'updatedAt'          => $r['updatedAt'] ?? $r['date'] ?? null,
    'note'               => $r['comment'] ?? $r['note'] ?? null,
  ];
}

// Сортировка: по subject, затем category
usort($out, function ($a, $b) {
  $sa = $a['subject'] ?? '';
  $sb = $b['subject'] ?? '';
  if ($sa !== $sb) return strcmp($sa, $sb);
  return strcmp($a['category'] ?? '', $b['category'] ?? '');
});

echo json_encode(array_values($out), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);