<?php
require_once __DIR__ . '/functions.php';
$postsDir = dirname(__DIR__) . '/posts';
$catSet = [];

foreach (glob($postsDir.'/*.html') as $f) {
    $h = file_get_contents($f);
    $m = parse_meta_from_html($h);
    foreach ($m['categories'] as $c) {
        if ($c !== '') $catSet[$c] = true;
    }
}
$categoriesForNav = array_keys($catSet);
natsort($categoriesForNav); // simple sort
?>

<!DOCTYPE html>
<html class="no-js" lang="ru_RU">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="yandex-verification" content="049701e98722a755" />

  <!-- Dynamic page title if provided by the page -->
  <title><?= isset($pageTitle) ? e($pageTitle) : 'Блог МРАКЕТПЛЕЙСЫ' ?></title>

  <meta name="description" content="Ищете фулфилмент? Мы поможем с хранением, упаковкой, отгрузкой и ведением магазина на маркетплейсах. Звоните +7 916 240 44 26">
  <meta name="keywords" content="фулфилмент, фулфилмент для маркетплейсов, склад, хранение, упаковка, доставка, ведение магазина, Wildberries, Ozon, Яндекс Маркет">
  <meta name="author" content="МРАКЕТПЛЕЙСЫ">
  <meta property="yandex" content="noretranslate">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">

  <!-- Site-wide canonical for non-post pages only -->
  <?php if (empty($og)): ?>
    <link rel="canonical" href="https://mraketplace.ru/" />
  <?php endif; ?>

  <!-- Site-wide OG/Twitter defaults (hidden on post pages when $og is set) -->
  <?php if (empty($og)): ?>
    <!-- Open Graph -->
    <meta property="og:type" content="website" />
    <meta property="og:locale" content="ru_RU" />
    <meta property="og:title" content="Фулфилмент для маркетплейсов | МРАКЕТПЛЕЙСЫ" />
    <meta property="og:description" content="Хранение, упаковка, отгрузка и ведение магазинов на маркетплейсах. Опыт с Wildberries, Ozon, Яндекс.Маркет, Авито." />
    <meta property="og:url" content="https://mraketplace.ru/" />
    <meta property="og:site_name" content="МРАКЕТПЛЕЙСЫ" />
    <meta property="og:image" content="https://mraketplace.ru/images/og-preview.jpg" />
    <meta property="og:image:alt" content="Фулфилмент для маркетплейсов — МРАКЕТПЛЕЙСЫ" />
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="630" />

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="Фулфилмент для маркетплейсов | МРАКЕТПЛЕЙСЫ" />
    <meta name="twitter:description" content="Услуги хранения, упаковки и продвижения для продавцов на маркетплейсах" />
    <meta name="twitter:image" content="https://mraketplace.ru/images/og-preview.jpg" />
  <?php endif; ?>

  <!-- Dynamic OG/Twitter per post (provided by view.php) -->
  <?php if (!empty($og) && is_array($og)): ?>
    <link rel="canonical" href="<?= e($og['url']) ?>">
    <meta property="og:site_name" content="МРАКЕТПЛЕЙСЫ">
    <meta property="og:title" content="<?= e($og['title']) ?>">
    <meta property="og:description" content="<?= e($og['description']) ?>">
    <meta property="og:url" content="<?= e($og['url']) ?>">
    <meta property="og:type" content="<?= e($og['type']) ?>">
    <?php if (!empty($og['image'])): ?>
      <meta property="og:image" content="<?= e($og['image']) ?>">
      <meta name="twitter:image" content="<?= e($og['image']) ?>">
      <!-- Optional fixed dims if all thumbs match -->
      <!-- <meta property="og:image:width" content="1200">
      <meta property="og:image:height" content="630"> -->
    <?php endif; ?>
    <?php if (!empty($og['published'])): ?>
      <meta property="article:published_time" content="<?= e($og['published']) ?>">
    <?php endif; ?>
    <?php if (!empty($og['tags']) && is_array($og['tags'])): ?>
      <?php foreach ($og['tags'] as $t): ?>
        <meta property="article:tag" content="<?= e($t) ?>">
      <?php endforeach; ?>
    <?php endif; ?>

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($og['title']) ?>">
    <meta name="twitter:description" content="<?= e($og['description']) ?>">
    <meta name="twitter:url" content="<?= e($og['url']) ?>">
  <?php endif; ?>

  <!-- Favicons -->
  <link rel="icon" type="image/png" href="/favicon/favicon-96x96.png" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
  <link rel="shortcut icon" href="/favicon.ico" />
  <link rel="apple-touch-icon" sizes="180x180" href="/favicon/apple-touch-icon.png" />
  <link rel="manifest" href="/favicon/site.webmanifest" />

  <!-- Stylesheets -->
  <link rel="stylesheet" href="/blog/css/base.css">
  <link rel="stylesheet" href="/blog/css/vendor.css">
  <link rel="stylesheet" href="/blog/css/main.css">
</head>
<body>
<div id="preloader"><div id="loader" class="dots-fade"><div></div><div></div><div></div></div></div>
<div id="top" class="s-wrap site-wrapper">
  <!-- Your header HTML from the template -->
  <header class="s-header">
    <!-- copy your header/nav markup exactly; make "Home" link to index.php -->
    <!-- for simplicity, keep category menu static for now -->
    <div class="header__top">
      <div class="header__logo">
        <a class="site-logo" href="index.php">
          <img src="/blog/images/logo.svg" alt="">
        </a>
      </div>
    </div>
    <nav class="header__nav-wrap">
      <ul class="header__nav">
        <li class="current"><a href="/index.html" title="">На главную</a></li>
        <li class="has-children">
  <a href="#0" title="">Категории</a>
  <ul class="sub-menu">
    <?php if (!empty($categoriesForNav)): ?>
      <?php foreach ($categoriesForNav as $cat): ?>
        <li><a href="/blog/category/<?= urlencode($cat) ?>"><?= e($cat) ?></a></li>
      <?php endforeach; ?>
    <?php else: ?>
      <li><a href="#0">Нет категорий</a></li>
    <?php endif; ?>
  </ul>
</li>
        <li><a href="#" title="">Блог</a></li>
        <li><a href="#" title="">О нас</a></li>
        <li><a href="#" title="">Контакты</a></li>
      </ul>
      <ul class="header__social">
        <li class="ss-youtube"><a href="https://youtube.com/@mraketplace"><span class="screen-reader-text">Youtube</span></a></li>
        <li class="ss-telegram"><a href="https://t.me/mraketplace"><span class="screen-reader-text">Telegram</span></a></li>
        <li class="ss-vk"><a href="https://vk.com/mraketplace"><span class="screen-reader-text">VK</span></a></li>
        <li class="ss-rss"><a href="https://yandex.ru"><span class="screen-reader-text">RSS</span></a></li>
      </ul>
    </nav>
    <a href="#0" class="header__menu-toggle"><span>Меню</span></a>
  </header>
  <!-- End header -->
<!-- search
        ================================================== -->
        <div class="s-search">

            <div class="search-block">
    
                <form role="search" method="get" class="search-form" action="/blog/search.php">
  <label>
    <span class="hide-content">Искать:</span>
    <input type="search" class="search-field" placeholder="Начните вводить запрос" value="" name="q" title="Ищем:" autocomplete="off">
  </label>
  <input type="submit" class="search-submit" value="Найти">
</form>
    
                <a href="#0" title="Закрыть поиск" class="search-close">Закрыть поиск</a>
    
            </div>  <!-- end search-block -->

            <!-- search modal trigger -->
            <a href="#0" class="search-trigger">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:rgba(0, 0, 0, 1);transform:;-ms-filter:"><path d="M10,18c1.846,0,3.543-0.635,4.897-1.688l4.396,4.396l1.414-1.414l-4.396-4.396C17.365,13.543,18,11.846,18,10 c0-4.411-3.589-8-8-8s-8,3.589-8,8S5.589,18,10,18z M10,4c3.309,0,6,2.691,6,6s-2.691,6-6,6s-6-2.691-6-6S6.691,4,10,4z"></path></svg>
                <span>Поиск</span>
            </a>
            <span class="search-line"></span>

        </div> <!-- end s-search -->
