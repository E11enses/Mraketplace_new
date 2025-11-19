<?php
// api/wb-availability.php
// Groups WB acceptance data by warehouse, then collapses to one row per boxTypeID.
// Color logic will be handled on the frontend.

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

$ch = curl_init('https://supplies-api.wildberries.ru/api/v1/acceptance/coefficients');
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
  echo json_encode(array());
  exit;
}

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
    // latest date on warehouse level
    $prevT = strtotime($byWh[$wid]['updatedAt']);
    $currT = strtotime($date);
    if ($prevT !== false && $currT !== false && $currT > $prevT) {
      $byWh[$wid]['updatedAt'] = $date;
    }
    if (isset($row['isSortingCenter']) && $row['isSortingCenter']) {
      $byWh[$wid]['isSortingCenter'] = true;
    }
  }

  // Normalize number-like fields
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

// Step 2: collapse duplicates by boxTypeID (latest date + OR allowUnload)
foreach ($byWh as $wid => $wh) {
  $byType = array(); // boxTypeID => mergedRow
  foreach ($wh['rows'] as $r) {
    $bt = $r['boxTypeID'];
    if ($bt === null) continue;

    if (!isset($byType[$bt])) {
      $byType[$bt] = $r; // initialize
    } else {
      // keep latest date
      $prevT = strtotime($byType[$bt]['date']);
      $currT = strtotime($r['date']);
      if ($prevT !== false && $currT !== false && $currT > $prevT) {
        // replace base fields with the latest row's values
        $byType[$bt]['date'] = $r['date'];
        $byType[$bt]['coefficient'] = $r['coefficient'];
        $byType[$bt]['deliveryBaseLiter'] = $r['deliveryBaseLiter'];
        $byType[$bt]['deliveryAdditionalLiter'] = $r['deliveryAdditionalLiter'];
        $byType[$bt]['deliveryCoef'] = $r['deliveryCoef'];
        $byType[$bt]['storageBaseLiter'] = $r['storageBaseLiter'];
        $byType[$bt]['storageAdditionalLiter'] = $r['storageAdditionalLiter'];
        $byType[$bt]['storageCoef'] = $r['storageCoef'];
      }
      // OR for allowUnload
      $byType[$bt]['allowUnload'] = ($byType[$bt]['allowUnload'] || $r['allowUnload']) ? true : false;
    }
  }
  // replace rows with collapsed values, sorted by boxTypeID
  $collapsed = array_values($byType);
  usort($collapsed, function($a, $b) {
    return intval($a['boxTypeID']) - intval($b['boxTypeID']);
  });
  $byWh[$wid]['rows'] = $collapsed;
}

// Step 3: flatten to array and sort (available first, then name)
$items = array_values($byWh);

usort($items, function($a, $b) {
  $aAvail = false; $bAvail = false;
  foreach ($a['rows'] as $r) { if (!empty($r['allowUnload'])) { $aAvail = true; break; } }
  foreach ($b['rows'] as $r) { if (!empty($r['allowUnload'])) { $bAvail = true; break; } }
  if ($aAvail !== $bAvail) return $aAvail ? -1 : 1;
  return strcmp($a['name'], $b['name']);
});

echo json_encode($items, JSON_UNESCAPED_UNICODE);