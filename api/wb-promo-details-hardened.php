<?php
declare(strict_types=1);

// Strong error reporting, but catch everything into JSON
ini_set('display_errors', '0');
error_reporting(E_ALL);

// JSON response helpers
function jexit(int $code, array $payload) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *');
  header('Cache-Control: no-store');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Token
$token = getenv('WB_ADS_TOKEN');
if (!$token) {
  $secretFile = __DIR__ . '/wb_ads_token.php';
  if (file_exists($secretFile)) {
    $token = trim((string)file_get_contents($secretFile));
  }
}
if (!$token) jexit(500, ['error' => 'WB_ADS_TOKEN not configured']);

// Params
function rfc3339(int $ts): string { return gmdate('Y-m-d\TH:i:s\Z', $ts); }
$now = time();

$startDateTime = $_GET['startDateTime'] ?? rfc3339($now);
$endDateTime   = $_GET['endDateTime']   ?? rfc3339($now + 60 * 86400);
$allPromo = isset($_GET['allPromo'])
  ? (($_GET['allPromo'] === '1' || strtolower((string)$_GET['allPromo']) === 'true') ? 'true' : 'false')
  : 'true';
$limit  = isset($_GET['limit'])  && is_numeric($_GET['limit'])  ? max(1, min(1000, (int)$_GET['limit'])) : 500;
$offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

// Header strategy: try both if needed
// You can force one via ?header=X or ?header=A
$forceHeader = isset($_GET['header']) ? strtoupper((string)$_GET['header']) : null;
$headerOrder = $forceHeader === 'X' ? ['X-Api-Key', 'Authorization']
             : ($forceHeader === 'A' ? ['Authorization', 'X-Api-Key']
             : ['Authorization', 'X-Api-Key']);

function curl_json(string $url, string $hdrName, string $token): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
      $hdrName . ': ' . $token,
      'Accept: application/json',
    ],
  ]);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  return [$code, $body, $err];
}

function decode_or_error(string $stage, int $code, ?string $body, ?string $err) {
  if ($body === false || $body === null) {
    jexit(500, ['error' => "Curl error ($stage)", 'detail' => $err]);
  }
  if ($code < 200 || $code >= 300) {
    jexit($code, ['error' => "WB API error ($stage)", 'status' => $code, 'detail' => $body]);
  }
  $json = json_decode($body, true);
  if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
    jexit(502, ['error' => "JSON decode error ($stage)", 'json_error' => json_last_error_msg(), 'raw_preview' => mb_substr($body, 0, 400, 'UTF-8')]);
  }
  return $json;
}

// Try headers in order until success
$attempts = [];
foreach ($headerOrder as $HDR) {
  // 1) List
  $listQS = http_build_query([
    'startDateTime' => $startDateTime,
    'endDateTime'   => $endDateTime,
    'allPromo'      => $allPromo,
    'limit'         => $limit,
    'offset'        => $offset,
  ]);
  $listURL = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions?' . $listQS;
  [$c1, $b1, $e1] = curl_json($listURL, $HDR, $token);

  // If list fails, record and try next header strategy
  if (!($c1 >= 200 && $c1 < 300)) {
    $attempts[] = ['stage' => 'list', 'header' => $HDR, 'status' => $c1, 'detail' => $b1];
    continue;
  }
  $listJson = decode_or_error("list using $HDR", $c1, $b1, $e1);
  $data = $listJson['data'] ?? $listJson;
  $promosList = [];
  if (is_array($data)) {
    if (isset($data['promotions']) && is_array($data['promotions'])) $promosList = $data['promotions'];
    else $promosList = $data;
  }

  $ids = [];
  foreach ((array)$promosList as $p) {
    if (!is_array($p)) continue;
    $id = $p['id'] ?? ($p['promotionId'] ?? null);
    if ($id === null) continue;
    $i = (int)$id;
    if ($i > 0) $ids[] = $i;
  }
  $ids = array_values(array_unique($ids));
  if (!$ids) jexit(200, []); // no promotions in window

  if (count($ids) > 100) $ids = array_slice($ids, 0, 100);

  // 2) Details
  $qs = [];
  foreach ($ids as $i) $qs[] = 'promotionIDs=' . rawurlencode((string)$i);
  $detailsURL = 'https://dp-calendar-api.wildberries.ru/api/v1/calendar/promotions/details?' . implode('&', $qs);
  [$c2, $b2, $e2] = curl_json($detailsURL, $HDR, $token);
  if (!($c2 >= 200 && $c2 < 300)) {
    $attempts[] = ['stage' => 'details', 'header' => $HDR, 'status' => $c2, 'detail' => $b2];
    // Try next header type
    continue;
  }

  $detJson = decode_or_error("details using $HDR", $c2, $b2, $e2);
  $detData = $detJson['data'] ?? $detJson;
  $promos = (isset($detData['promotions']) && is_array($detData['promotions'])) ? $detData['promotions'] : [];

  // Normalize
  function iso_or_null($v) {
    if (!is_string($v) || $v === '') return null;
    $t = strtotime($v);
    if ($t === false) return null;
    return gmdate('c', $t);
  }

  $nowTs = time();
  $out = [];
  foreach ($promos as $p) {
    if (!is_array($p)) continue;
    $start = iso_or_null($p['startDateTime'] ?? null);
    $end   = iso_or_null($p['endDateTime'] ?? null);
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
      'inPromoActionLeftovers'    => isset($p['inPromoActionLeftovers']) ? (int)$p['inPromoActionLeftovers'] : null,
      'inPromoActionTotal'        => isset($p['inPromoActionTotal']) ? (int)$p['inPromoActionTotal'] : null,
      'notInPromoActionLeftovers' => isset($p['notInPromoActionLeftovers']) ? (int)$p['notInPromoActionLeftovers'] : null,
      'notInPromoActionTotal'     => isset($p['notInPromoActionTotal']) ? (int)$p['notInPromoActionTotal'] : null,
      'participationPercentage'   => isset($p['participationPercentage']) ? (int)$p['participationPercentage'] : null,
      'type'                      => isset($p['type']) ? (string)$p['type'] : null,
      'exceptionProductsCount'    => isset($p['exceptionProductsCount']) ? (int)$p['exceptionProductsCount'] : null,
      'ranging'                   => isset($p['ranging']) && is_array($p['ranging']) ? array_values($p['ranging']) : [],
    ];
  }
  usort($out, static function ($a, $b) {
    $as = $a['startDate'] ?? '';
    $bs = $b['startDate'] ?? '';
    $cmp = strcmp($as, $bs);
    if ($cmp !== 0) return $cmp;
    return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
  });

  jexit(200, $out);
}

// If we got here, all attempts failed
jexit(502, ['error' => 'All header strategies failed', 'attempts' => $attempts]);