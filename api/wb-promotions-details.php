<?php
// api/wb-promotions-details.php
// Returns detailed promotions list (name, description, advantages, counts, etc.)
// from WB endpoint: GET /api/v1/calendar/promotions/details

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Token: ENV -> file wb_ads_token.php (plain text)
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
  echo json_encode(['error' => 'WB_ADS_TOKEN not configured']);
  exit;
}

function rfc3339_utc(int $ts): string {
  return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

$now = time();
// Automatic 60-day window from now
$startDateTime = isset($_GET['startDateTime']) && $_GET['startDateTime'] !== '' ? (string)$_GET['startDateTime'] : rfc3339_utc($now);
$endDateTime   = isset($_GET['endDateTime'])   && $_GET['endDateTime']   !== '' ? (string)$_GET['endDateTime']   : rfc3339_utc($now + 60 * 86400);

// allPromo: false by default (only available to participate)
$allPromo = isset($_GET['allPromo'])
  ? (($_GET['allPromo'] === '1' || strtolower((string)$_GET['allPromo']) === 'true') ? 'true' : 'false')
  : 'false';

// Pagination
$limit  = isset($_GET['limit'])  && is_numeric($_GET['limit'])  ? max(1, min(1000, (int)$_GET['limit'])) : 500;
$offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

$endpointBase = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions/details';
$q = [
  'startDateTime' => $startDateTime,
  'endDateTime'   => $endDateTime,
  'allPromo'      => $allPromo,
  'limit'         => $limit,
  'offset'        => $offset,
];
$endpoint = $endpointBase . '?' . http_build_query($q);

// Call WB with your preferred Authorization header
$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_HTTPHEADER => [
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
if ($raw === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(502);
  echo json_encode([
    'error' => 'JSON decode error',
    'json_error' => json_last_error_msg(),
    'raw_preview' => mb_substr($resp, 0, 400, 'UTF-8'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Expecting: { data: { promotions: [...] } }
$data = [];
if (isset($raw['data']) && is_array($raw['data'])) {
  $data = $raw['data'];
} else {
  // Fallback: some deployments might return top-level 'promotions'
  $data = $raw;
}
$promos = [];
if (isset($data['promotions']) && is_array($data['promotions'])) {
  $promos = $data['promotions'];
} elseif (isset($data[0]) && is_array($data[0])) {
  // Fallback if array returned directly
  $promos = $data;
}

function isoOrNull($v) {
  if (!is_string($v) || $v === '') return null;
  $t = strtotime($v);
  if ($t === false) return null;
  return gmdate('c', $t);
}

$nowTs = time();
$out = [];
foreach ($promos as $p) {
  if (!is_array($p)) continue;

  $id   = isset($p['id']) ? (int)$p['id'] : null;
  $name = isset($p['name']) ? (string)$p['name'] : null;

  $start = isoOrNull($p['startDateTime'] ?? null);
  $end   = isoOrNull($p['endDateTime'] ?? null);

  $active = null;
  if ($start !== null) {
    $s = strtotime($start);
    $e = $end ? strtotime($end) : null;
    if ($s !== false) $active = ($nowTs >= $s) && ($e === null || $nowTs <= $e);
  }

  $out[] = [
    'id'                         => $id,
    'name'                       => $name,
    'description'                => isset($p['description']) ? (string)$p['description'] : null,
    'advantages'                 => isset($p['advantages']) && is_array($p['advantages']) ? array_values($p['advantages']) : [],
    'startDate'                  => $start,
    'endDate'                    => $end,
    'active'                     => $active,
    'inPromoActionLeftovers'     => isset($p['inPromoActionLeftovers']) ? (int)$p['inPromoActionLeftovers'] : null,
    'inPromoActionTotal'         => isset($p['inPromoActionTotal']) ? (int)$p['inPromoActionTotal'] : null,
    'notInPromoActionLeftovers'  => isset($p['notInPromoActionLeftovers']) ? (int)$p['notInPromoActionLeftovers'] : null,
    'notInPromoActionTotal'      => isset($p['notInPromoActionTotal']) ? (int)$p['notInPromoActionTotal'] : null,
    'participationPercentage'    => isset($p['participationPercentage']) ? (int)$p['participationPercentage'] : null,
    'type'                       => isset($p['type']) ? (string)$p['type'] : null, // "regular" | "auto"
    'exceptionProductsCount'     => isset($p['exceptionProductsCount']) ? (int)$p['exceptionProductsCount'] : null,
    'ranging'                    => isset($p['ranging']) && is_array($p['ranging']) ? array_values($p['ranging']) : [],
  ];
}

// Sort: active first, then start date asc, then name
usort($out, static function ($a, $b) {
  $aAct = $a['active'] ? 1 : 0;
  $bAct = $b['active'] ? 1 : 0;
  if ($aAct !== $bAct) return $bAct - $aAct;
  $as = $a['startDate'] ?? '';
  $bs = $b['startDate'] ?? '';
  $cmp = strcmp($as, $bs);
  if ($cmp !== 0) return $cmp;
  return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

echo json_encode(array_values($out), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);