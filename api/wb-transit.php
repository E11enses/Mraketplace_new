<?php
// api/wb-transit.php
// Fetches WB transit tariffs (FBW) and returns a normalized list of routes.
// Reads token like wb-availability.php: from env WB_SUPPLIES_TOKEN or from wb_token.php (plain text).
// Optional local filters: ?fromWarehouseId=...&toWarehouseId=...

// 1) Token: env -> file
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
  echo json_encode(array('error' => 'WB_SUPPLIES_TOKEN not configured'));
  exit;
}

// 2) Upstream endpoint
$endpoint = 'https://supplies-api.wildberries.ru/api/v1/transit-tariffs';

// 3) Call WB
$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
  'Authorization: ' . $token, // If WB requires X-Api-Key instead, change here.
  'Accept: application/json'
));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
  http_response_code(500);
  echo json_encode(array('error' => 'Curl error', 'detail' => $err));
  exit;
}
if ($http < 200 || $http >= 300) {
  http_response_code($http);
  echo json_encode(array('error' => 'WB API error', 'status' => $http, 'detail' => $resp));
  exit;
}

$data = json_decode($resp, true);
if (!is_array($data)) {
  // Some WB endpoints wrap into {data:[...]} — try to unwrap
  if (isset($data['data']) && is_array($data['data'])) {
    $data = $data['data'];
  } else if (isset($data['result']) && is_array($data['result'])) {
    $data = $data['result'];
  } else {
    echo json_encode(array());
    exit;
  }
}

// Helpers
function toFloatOrNull($v) {
  if ($v === null) return null;
  if (is_numeric($v)) return floatval($v);
  if (is_string($v)) {
    $x = str_replace(' ', '', $v);
    $x = str_replace(',', '.', $x);
    if (is_numeric($x)) return floatval($x);
  }
  return null;
}
function toIntOrNull($v) {
  if ($v === null) return null;
  if (is_numeric($v)) return intval($v);
  return null;
}
function parseDateOrNull($s) {
  if (!$s || !is_string($s)) return null;
  $t = strtotime($s);
  return $t === false ? null : $t;
}

// 4) Normalize each row
$norm = array(); // list of rows before collapsing
foreach ($data as $row) {
  // Try different key variants just in case
  $fromId = null;
  if (isset($row['fromWarehouseId'])) $fromId = $row['fromWarehouseId'];
  else if (isset($row['from_id'])) $fromId = $row['from_id'];
  else if (isset($row['fromWarehouse'])) $fromId = $row['fromWarehouse'];

  $toId = null;
  if (isset($row['toWarehouseId'])) $toId = $row['toWarehouseId'];
  else if (isset($row['to_id'])) $toId = $row['to_id'];
  else if (isset($row['toWarehouse'])) $toId = $row['toWarehouse'];

  if ($fromId === null || $toId === null) {
    // Skip rows without direction
    continue;
  }

  $tariff = null;
  if (array_key_exists('tariff', $row)) $tariff = toFloatOrNull($row['tariff']);
  else if (array_key_exists('price', $row)) $tariff = toFloatOrNull($row['price']);
  else if (array_key_exists('amount', $row)) $tariff = toFloatOrNull($row['amount']);
  else if (array_key_exists('cost', $row)) $tariff = toFloatOrNull($row['cost']);

  $currency = 'RUB';
  if (isset($row['currency']) && is_string($row['currency'])) $currency = $row['currency'];
  else if (isset($row['curr']) && is_string($row['curr'])) $currency = $row['curr'];

  $leadDays = null;
  if (isset($row['leadTimeDays'])) $leadDays = toIntOrNull($row['leadTimeDays']);
  else if (isset($row['days'])) $leadDays = toIntOrNull($row['days']);
  else if (isset($row['deliveryDays'])) $leadDays = toIntOrNull($row['deliveryDays']);

  $active = null;
  if (isset($row['active'])) $active = (bool)$row['active'];
  else if (isset($row['enabled'])) $active = (bool)$row['enabled'];

  $updatedAt = null;
  if (isset($row['updatedAt'])) $updatedAt = $row['updatedAt'];
  else if (isset($row['date'])) $updatedAt = $row['date'];

  $serviceType = isset($row['serviceType']) ? strval($row['serviceType']) : null;
  $weightLimitKg = isset($row['weightLimitKg']) ? toFloatOrNull($row['weightLimitKg']) : null;
  $note = isset($row['comment']) ? strval($row['comment']) : null;

  $norm[] = array(
    'fromWarehouseId' => strval($fromId),
    'toWarehouseId' => strval($toId),
    'tariff' => $tariff,
    'currency' => $currency,
    'leadTimeDays' => $leadDays,
    'active' => $active,
    'updatedAt' => $updatedAt,
    'serviceType' => $serviceType,
    'weightLimitKg' => $weightLimitKg,
    'comment' => $note,
  );
}

// 5) Collapse duplicates by (fromWarehouseId, toWarehouseId)
// Keep the latest updatedAt; OR-merge "active" where applicable.
// Prefer non-null values for tariff/leadTimeDays/currency if one of duplicates has them.
$byRoute = array(); // key "from->to" => merged
foreach ($norm as $r) {
  $key = $r['fromWarehouseId'] . '->' . $r['toWarehouseId'];

  if (!isset($byRoute[$key])) {
    $byRoute[$key] = $r;
  } else {
    $curr = $byRoute[$key];

    // updatedAt: pick latest timestamp
    $tCurr = parseDateOrNull($curr['updatedAt']);
    $tNew  = parseDateOrNull($r['updatedAt']);
    if ($tNew !== null && ($tCurr === null || $tNew > $tCurr)) {
      // replace main values if the new row is newer
      $curr['tariff'] = $r['tariff'] !== null ? $r['tariff'] : $curr['tariff'];
      $curr['currency'] = $r['currency'] ?? $curr['currency'];
      $curr['leadTimeDays'] = $r['leadTimeDays'] !== null ? $r['leadTimeDays'] : $curr['leadTimeDays'];
      $curr['updatedAt'] = $r['updatedAt'];
      $curr['serviceType'] = $r['serviceType'] ?? $curr['serviceType'];
      $curr['weightLimitKg'] = $r['weightLimitKg'] !== null ? $r['weightLimitKg'] : $curr['weightLimitKg'];
      $curr['comment'] = $r['comment'] ?? $curr['comment'];
    } else {
      // Even if not newer, try to fill nulls
      if ($curr['tariff'] === null && $r['tariff'] !== null) $curr['tariff'] = $r['tariff'];
      if ($curr['leadTimeDays'] === null && $r['leadTimeDays'] !== null) $curr['leadTimeDays'] = $r['leadTimeDays'];
      if (empty($curr['currency']) && !empty($r['currency'])) $curr['currency'] = $r['currency'];
      if ($curr['weightLimitKg'] === null && $r['weightLimitKg'] !== null) $curr['weightLimitKg'] = $r['weightLimitKg'];
      if (empty($curr['serviceType']) && !empty($r['serviceType'])) $curr['serviceType'] = $r['serviceType'];
      if (empty($curr['comment']) && !empty($r['comment'])) $curr['comment'] = $r['comment'];
    }

    // active: OR if both have boolean; if one is null, prefer non-null
    if ($curr['active'] === null) $curr['active'] = $r['active'];
    else if ($r['active'] !== null) $curr['active'] = ($curr['active'] || $r['active']) ? true : false;

    $byRoute[$key] = $curr;
  }
}

// 6) Apply local filters if provided
$filterFrom = isset($_GET['fromWarehouseId']) ? trim(strval($_GET['fromWarehouseId'])) : '';
$filterTo   = isset($_GET['toWarehouseId']) ? trim(strval($_GET['toWarehouseId'])) : '';

$items = array_values($byRoute);
if ($filterFrom !== '') {
  $items = array_values(array_filter($items, function($x) use ($filterFrom) {
    return isset($x['fromWarehouseId']) && strval($x['fromWarehouseId']) === $filterFrom;
  }));
}
if ($filterTo !== '') {
  $items = array_values(array_filter($items, function($x) use ($filterTo) {
    return isset($x['toWarehouseId']) && strval($x['toWarehouseId']) === $filterTo;
  }));
}

// 7) Sorting: active first, then fromWarehouseId, then toWarehouseId
usort($items, function($a, $b) {
  $aAct = isset($a['active']) ? (bool)$a['active'] : false;
  $bAct = isset($b['active']) ? (bool)$b['active'] : false;
  if ($aAct !== $bAct) return $aAct ? -1 : 1;

  $af = strcmp(strval($a['fromWarehouseId']), strval($b['fromWarehouseId']));
  if ($af !== 0) return $af;
  return strcmp(strval($a['toWarehouseId']), strval($b['toWarehouseId']));
});

// 8) Output
echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);