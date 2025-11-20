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

// ВАЖНО: endpoint из вашей ссылки
$endpoint = 'https://common-api.wildberries.ru/api/v1/tariffs/commission';

// Пробрасываем любые query-параметры на апстрим (если будут поддерживаться)
if (!empty($_GET)) {
  $endpoint .= '?' . http_build_query($_GET);
}

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_HTTPHEADER => [
    // Если вернёт 401 — замените на 'X-Api-Key: ' . $token
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
    'raw_preview' => mb_substr($resp, 0, 500, 'UTF-8'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Расслаиваем контейнеры
$rows = [];
if (isset($data['data']) && is_array($data['data'])) $rows = $data['data'];
elseif (isset($data['result']) && is_array($data['result'])) $rows = $data['result'];
elseif (is_array($data)) $rows = $data;

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

// Нормализация полей (подстроена к типичным названиям)
$out = [];
foreach ($rows as $r) {
  if (!is_array($r)) continue;

  $subject = $r['subjectName'] ?? ($r['subject'] ?? null);
  $category = $r['categoryName'] ?? ($r['tnvedName'] ?? ($r['groupName'] ?? null));
  $tnved = $r['tnved'] ?? ($r['tnvedCode'] ?? null);

  $commission = numOrNull($r['commission'] ?? ($r['percent'] ?? $r['commissionPercent'] ?? null)); // %
  $minCommission = numOrNull($r['minCommission'] ?? ($r['min'] ?? null)); // ₽
  $calcType = $r['calcType'] ?? ($r['type'] ?? null);
  $region = $r['region'] ?? ($r['country'] ?? null);
  $brand = $r['brand'] ?? null;
  $nmId = $r['nmId'] ?? ($r['nm'] ?? null);
  $updatedAt = $r['updatedAt'] ?? ($r['date'] ?? null);
  $note = $r['comment'] ?? ($r['note'] ?? null);

  $out[] = [
    'subject' => $subject,
    'category' => $category,
    'tnved' => $tnved,
    'commissionPercent' => $commission,
    'minCommission' => $minCommission,
    'calcType' => $calcType,
    'region' => $region,
    'brand' => $brand,
    'nmId' => $nmId,
    'updatedAt' => $updatedAt,
    'note' => $note,
  ];
}

// Сортировка
usort($out, function($a, $b) {
  $sa = $a['subject'] ?? '';
  $sb = $b['subject'] ?? '';
  if ($sa !== $sb) return strcmp($sa, $sb);
  return strcmp($a['category'] ?? '', $b['category'] ?? '');
});

echo json_encode(array_values($out), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);