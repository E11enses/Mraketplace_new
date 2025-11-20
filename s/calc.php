<?php
// public/s/calc.php
$uri = $_SERVER['REQUEST_URI'];
$slug = '';
if (preg_match('#^/s/([^/?]+)#', $uri, $m)) {
  $slug = $m[1];
}
$storeFile = __DIR__ . '/../data/store.json';
$state = null;
if ($slug && file_exists($storeFile)) {
  $store = json_decode(file_get_contents($storeFile), true);
  if (is_array($store) && array_key_exists($slug, $store)) {
    $state = $store[$slug];
  }
}
if (!$state) {
  header('Content-Type: text/html; charset=utf-8', true, 404);
  echo '<!doctype html><meta charset="utf-8">Not found';
  exit;
}
unset($state['_ts']);
$safe = json_encode($state, JSON_UNESCAPED_UNICODE);
$calcPath = '/calc.html'; // your main calculator page
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Loading…</title>
  </head>
  <body>
    <script>
      try {
        sessionStorage.setItem("calcState", <?php echo $safe; ?>);
      } catch (e) {}
      location.replace("<?php echo htmlspecialchars($calcPath, ENT_QUOTES); ?>");
    </script>
  </body>
</html>