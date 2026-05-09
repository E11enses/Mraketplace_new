<?php
// api/wb-transit.php — SUPPLIES API (FBW transit tariffs)
// С кэшированием: данные запрашиваются у WB раз в сутки,
// хранятся в файле cache/wb-transit.json

declare(strict_types=1);

// ── Настройки кэша ──────────────────────────────────────────
$cacheFile = __DIR__ . '/cache/wb-transit.json';
$cacheTTL  = 86400; // секунд = 24 часа (измените по желанию)
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
            // Добавляем мета-поле, чтобы фронтенд знал, что это кэш
            // (необязательно — можно убрать эти три строки)
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                echo $cached;
                exit;
            }
        }
    }
}
// ─────────────────────────────────────────────────────────────

// ── Токен ────────────────────────────────────────────────────
$token = getenv('WB_SUPPLIES_TOKEN');
if (!$token) {
    $secretFile = __DIR__ . '/wb_token.php';
    if (file_exists($secretFile)) {
        $token = trim((string)file_get_contents($secretFile));
    }
}

if (!$token) {
    http_response_code(500);
    echo json_encode(['error' => 'WB_SUPPLIES_TOKEN not configured']);
    exit;
}

// ── Запрос к WB API ──────────────────────────────────────────
$endpoint = 'https://supplies-api.wildberries.ru/api/v1/transit-tariffs';

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_HTTPHEADER     => [
        'Authorization: ' . $token,
        'Accept: application/json',
    ],
]);

$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    // Если запрос упал — пробуем отдать устаревший кэш
    if (file_exists($cacheFile)) {
        $stale = file_get_contents($cacheFile);
        if ($stale) { echo $stale; exit; }
    }
    http_response_code(500);
    echo json_encode(['error' => 'Curl error', 'detail' => $err], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($http < 200 || $http >= 300) {
    // При 429 / 5xx — отдаём устаревший кэш, не ломаем пользователю страницу
    if (file_exists($cacheFile)) {
        $stale = file_get_contents($cacheFile);
        if ($stale) { echo $stale; exit; }
    }
    http_response_code($http);
    echo json_encode(['error' => 'WB API error', 'status' => $http, 'detail' => $resp], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = json_decode($resp, true);

if ($raw === null && json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode([
        'error'       => 'JSON decode error',
        'json_error'  => json_last_error_msg(),
        'raw_preview' => mb_substr($resp, 0, 400, 'UTF-8'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Нормализация ─────────────────────────────────────────────
if (!is_array($raw)) {
    $raw = [];
} elseif (isset($raw['data']) && is_array($raw['data'])) {
    $raw = $raw['data'];
} elseif (isset($raw['result']) && is_array($raw['result'])) {
    $raw = $raw['result'];
} elseif (isset($raw['rows']) && is_array($raw['rows'])) {
    $raw = $raw['rows'];
}

function numOrNull($v) {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return 0 + $v;
    if (is_string($v)) {
        $x = str_replace([' ', ','], ['', '.'], $v);
        if (is_numeric($x)) return 0 + $x;
    }
    return null;
}

$items = [];

foreach ($raw as $r) {
    if (!is_array($r)) continue;

    $fromName = isset($r['transitWarehouseName'])     ? (string)$r['transitWarehouseName']     : null;
    $toName   = isset($r['destinationWarehouseName']) ? (string)$r['destinationWarehouseName'] : null;

    $fromId = null;
    if (isset($r['fromWarehouseId']))  $fromId = (string)$r['fromWarehouseId'];
    elseif (isset($r['fromWarehouseID'])) $fromId = (string)$r['fromWarehouseID'];

    $toId = null;
    if (isset($r['toWarehouseId']))  $toId = (string)$r['toWarehouseId'];
    elseif (isset($r['toWarehouseID'])) $toId = (string)$r['toWarehouseID'];

    $activeFrom = isset($r['activeFrom']) ? (string)$r['activeFrom'] : null;
    $active = null;
    if ($activeFrom) {
        $ts = strtotime($activeFrom);
        if ($ts !== false) $active = (time() >= $ts);
    }

    $palletTariff = array_key_exists('palletTariff', $r) ? numOrNull($r['palletTariff']) : null;

    $boxRanges = [];
    $boxMin = null;
    $boxMax = null;

    if (isset($r['boxTariff']) && is_array($r['boxTariff'])) {
        foreach ($r['boxTariff'] as $t) {
            if (!is_array($t)) continue;
            $fromVol = isset($t['from'])  ? (int)$t['from']  : null;
            $toVol   = isset($t['to'])    ? (int)$t['to']    : null;
            $val     = array_key_exists('value', $t) ? numOrNull($t['value']) : null;
            if ($fromVol !== null && $val !== null) {
                $boxRanges[] = ['from' => $fromVol, 'to' => $toVol, 'value' => $val];
                if ($boxMin === null || $val < $boxMin) $boxMin = $val;
                if ($boxMax === null || $val > $boxMax) $boxMax = $val;
            }
        }
        usort($boxRanges, static fn($a, $b) => $a['from'] <=> $b['from']);
    }

    $items[] = [
        'fromWarehouseId'   => $fromId,
        'toWarehouseId'     => $toId,
        'fromWarehouseName' => $fromName,
        'toWarehouseName'   => $toName,
        'active'            => $active,
        'updatedAt'         => $activeFrom,
        'palletTariff'      => $palletTariff,
        'boxTariffRanges'   => $boxRanges,
        'boxMinPerLiter'    => $boxMin,
        'boxMaxPerLiter'    => $boxMax,
    ];
}

usort($items, static function ($a, $b) {
    $cmp = strcmp($a['fromWarehouseName'] ?? '', $b['fromWarehouseName'] ?? '');
    return $cmp !== 0 ? $cmp : strcmp($a['toWarehouseName'] ?? '', $b['toWarehouseName'] ?? '');
});

$result = array_values($items);
$json   = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// ── Сохраняем в кэш ──────────────────────────────────────────
$cacheDir = dirname($cacheFile);
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
file_put_contents($cacheFile, $json, LOCK_EX);
// ─────────────────────────────────────────────────────────────

echo $json;