<?php
// public/api/load.php?slug=xxxx
header('Content-Type: application/json; charset=utf-8');

$slug = isset($_GET['slug']) ? $_GET['slug'] : '';
if ($slug === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing slug']);
  exit;
}
$storeFile = __DIR__ . '/../data/store.json';
if (!file_exists($storeFile)) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Not found']);
  exit;
}
$store = json_decode(file_get_contents($storeFile), true);
if (!is_array($store) || !array_key_exists($slug, $store)) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Not found']);
  exit;
}
$state = $store[$slug];
unset($state['_ts']); // hide metadata
echo json_encode(['ok' => true, 'slug' => $slug, 'state' => $state], JSON_UNESCAPED_UNICODE);