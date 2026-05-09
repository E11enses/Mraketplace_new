<?php
// api/wb-availability.php
// Groups WB acceptance data by warehouse, then collapses to one row per boxTypeID.
// С кэшированием: данные запрашиваются у WB раз в сутки,
// хранятся в файле cache/wb-availability.json

// ── Настройки кэша ──────────────────────────────────────────
$cacheFile = __DIR__ . '/cache/wb-availability.json';
$cacheTTL  = 86400; // секунд = 24 часа
// ────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

// ── Отдаём кэш, если он свежий ───────────────────────────────
if (file_exists($cacheFile)) {
  $age = time() - filemtime($cacheFile);
  if ($age < $cacheTTL) {
    $cached = file_get_contents($cacheFile);
    if ($cached !== false && $cached !== '') {
      echo $cached;
      exit;
    }
  }
}
// ─────────────────────────────────────────────────────────────

// ── Токен ────────────────────────────────────────────────────
$token = getenv('WB_SUPPLIES_TOKEN');
if (!$token) {
  $secretFile = __DIR__ . '/wb_token.php';
  if (file_exists($secretFile)) {
    $token = trim(file_get_contents($secretFile));
  }
}

if (!$token) {
  http_response_code(500);
  echo json_encode(array('error' => 'WB_SUPPLIES_TOKEN not configured'));
  exit;
}

// ── Запрос к WB API ──────────────────────────────────────────
$ch = curl_init('https://common-api.wildberries.ru/api/tariffs/v1/acceptance/coefficients');
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
  'Authorization: ' . $token,
  'Accept: application/json'
));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
  // Curl-ошибка — отдаём устаревший кэш если есть
  if (file_exists($cacheFile)) {
    $stale = file_get_contents($cacheFile);
    if ($stale) { echo $stale; exit; }
  }
  http_response_code(500);
  echo json_encode(array('error' => 'Curl error', 'detail' => $err));
  exit;
}

if ($http < 200 || $http >= 300) {
  // 429 / 5xx — отдаём устаревший кэш, не ломаем страницу
  if (file_exists($cacheFile)) {
    $stale = file_get_contents($cacheFile);
    if ($stale) { echo $stale; exit; }
  }
  http_response_code($http);
  echo json_encode(array('error' => 'WB API error', 'status' => $http, 'detail' => $resp));
  exit;
}

$data = json_decode($resp, true);
if (!is_array($data)) {
  echo json_encode(array());
  exit;
}

// ── Нормализация ─────────────────────────────────────────────
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

// Step 1: group by warehouseID
$byWh = array();
foreach ($data as $row) {
  $wid = null;
  if (isset($row['warehouseID'])) $wid = $row['warehouseID'];
  else if (isset($row['warehouseId'])) $wid = $row['warehouseId'];
  if ($wid === null) continue;

  $name = isset($row['warehouseName']) ? $row['warehouseName'] : ('Warehouse ' . $wid);
  $date = isset($row['date']) ? $row['date'] : gmdate('c');

  if (!isset($byWh[$wid])) {
    $byWh[$wid] = array(
      'warehouseId' => strval($wid),
      'name' => strval($name),
      'isSortingCenter' => isset($row['isSortingCenter']) ? (bool)$row['isSortingCenter'] : false,
      'updatedAt' => $date,
      'rows' => array()
    );
  } else {
    $prevT = strtotime($byWh[$wid]['updatedAt']);
    $currT = strtotime($date);
    if ($prevT !== false && $currT !== false && $currT > $prevT) {
      $byWh[$wid]['updatedAt'] = $date;
    }
    if (isset($row['isSortingCenter']) && $row['isSortingCenter']) {
      $byWh[$wid]['isSortingCenter'] = true;
    }
  }

  $normalized = array(
    'date' => $date,
    'coefficient' => isset($row['coefficient']) ? toFloatOrNull($row['coefficient']) : null,
    'warehouseID' => $wid,
    'warehouseName' => $name,
    'allowUnload' => isset($row['allowUnload']) ? (bool)$row['allowUnload'] : false,
    'boxTypeID' => isset($row['boxTypeID']) ? intval($row['boxTypeID']) : null,
    'storageCoef' => isset($row['storageCoef']) ? toFloatOrNull($row['storageCoef']) : null,
    'deliveryCoef' => isset($row['deliveryCoef']) ? toFloatOrNull($row['deliveryCoef']) : null,
    'deliveryBaseLiter' => isset($row['deliveryBaseLiter']) ? toFloatOrNull($row['deliveryBaseLiter']) : null,
    'deliveryAdditionalLiter' => isset($row['deliveryAdditionalLiter']) ? toFloatOrNull($row['deliveryAdditionalLiter']) : null,
    'storageBaseLiter' => isset($row['storageBaseLiter']) ? toFloatOrNull($row['storageBaseLiter']) : null,
    'storageAdditionalLiter' => isset($row['storageAdditionalLiter']) ? toFloatOrNull($row['storageAdditionalLiter']) : null,
    'isSortingCenter' => isset($row['isSortingCenter']) ? (bool)$row['isSortingCenter'] : false
  );

  $byWh[$wid]['rows'][] = $normalized;
}

// Step 2: collapse duplicates by boxTypeID
foreach ($byWh as $wid => $wh) {
  $byType = array();
  foreach ($wh['rows'] as $r) {
    $bt = $r['boxTypeID'];
    if ($bt === null) continue;

    if (!isset($byType[$bt])) {
      $byType[$bt] = $r;
    } else {
      $prevT = strtotime($byType[$bt]['date']);
      $currT = strtotime($r['date']);
      if ($prevT !== false && $currT !== false && $currT > $prevT) {
        $byType[$bt]['date'] = $r['date'];
        $byType[$bt]['coefficient'] = $r['coefficient'];
        $byType[$bt]['deliveryBaseLiter'] = $r['deliveryBaseLiter'];
        $byType[$bt]['deliveryAdditionalLiter'] = $r['deliveryAdditionalLiter'];
        $byType[$bt]['deliveryCoef'] = $r['deliveryCoef'];
        $byType[$bt]['storageBaseLiter'] = $r['storageBaseLiter'];
        $byType[$bt]['storageAdditionalLiter'] = $r['storageAdditionalLiter'];
        $byType[$bt]['storageCoef'] = $r['storageCoef'];
      }
      $byType[$bt]['allowUnload'] = ($byType[$bt]['allowUnload'] || $r['allowUnload']) ? true : false;
    }
  }
  $collapsed = array_values($byType);
  usort($collapsed, function($a, $b) {
    return intval($a['boxTypeID']) - intval($b['boxTypeID']);
  });
  $byWh[$wid]['rows'] = $collapsed;
}

// Step 3: flatten and sort
$items = array_values($byWh);

usort($items, function($a, $b) {
  $aAvail = false; $bAvail = false;
  foreach ($a['rows'] as $r) { if (!empty($r['allowUnload'])) { $aAvail = true; break; } }
  foreach ($b['rows'] as $r) { if (!empty($r['allowUnload'])) { $bAvail = true; break; } }
  if ($aAvail !== $bAvail) return $aAvail ? -1 : 1;
  return strcmp($a['name'], $b['name']);
});

$json = json_encode($items, JSON_UNESCAPED_UNICODE);

// ── Сохраняем в кэш ──────────────────────────────────────────
$cacheDir = dirname($cacheFile);
if (!is_dir($cacheDir)) {
  mkdir($cacheDir, 0755, true);
}
file_put_contents($cacheFile, $json, LOCK_EX);
// ─────────────────────────────────────────────────────────────

echo $json;