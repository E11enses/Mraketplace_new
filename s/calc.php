<?php
// public/s/calc.php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

$uri = $_SERVER['REQUEST_URI'] ?? '';
$slug = '';
if (preg_match('#^/s/([^/?]+)#', $uri, $m)) {
  $slug = $m[1];
}

$storeFile = __DIR__ . '/../api/data/store.json';
$state = null;
if ($slug && is_file($storeFile)) {
  $store = json_decode(file_get_contents($storeFile), true);
  if (is_array($store) && array_key_exists($slug, $store)) {
    $state = $store[$slug];
  }
}
if (!$state) {
  http_response_code(404);
  echo '<!doctype html><meta charset="utf-8">Not found';
  exit;
}

unset($state['_ts']);

$calcPath = 'utilities/calc.html'; // your main calculator page
?>
<!doctype html>
<html lang="ru">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Загрузка…</title>
  </head>
  <body>
    <script>
      try {
        // Store a JSON STRING so your app can JSON.parse it
        sessionStorage.setItem(
          "calcState",
          JSON.stringify(<?php echo json_encode($state, JSON_UNESCAPED_UNICODE); ?>)
        );
      } catch (e) {}
      location.replace("<?php echo htmlspecialchars($calcPath, ENT_QUOTES); ?>");
    </script>
  </body>
</html>