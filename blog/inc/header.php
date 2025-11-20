<!DOCTYPE html>
<html class="no-js" lang="en">
<head>
  <meta charset="utf-8">
  <title>Typerite</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/blog/css/base.css">
  <link rel="stylesheet" href="/blog/css/vendor.css">
  <link rel="stylesheet" href="/blog/css/main.css">
  <script src="/blog/js/modernizr.js"></script>
  <link rel="apple-touch-icon" sizes="180x180" href="/blog/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="/blog/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/blog/favicon-16x16.png">
  <link rel="manifest" href="/blog/site.webmanifest">
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
          <img src="/blog/images/logo.svg" alt="Homepage">
        </a>
      </div>
    </div>
    <nav class="header__nav-wrap">
      <ul class="header__nav">
        <li class="current"><a href="index.php" title="">Home</a></li>
        <li><a href="#" title="">Categories</a></li>
        <li><a href="#" title="">Blog</a></li>
        <li><a href="#" title="">About</a></li>
        <li><a href="#" title="">Contact</a></li>
      </ul>
      <ul class="header__social">
        <li class="ss-facebook"><a href="https://facebook.com/"><span class="screen-reader-text">Facebook</span></a></li>
      </ul>
    </nav>
    <a href="#0" class="header__menu-toggle"><span>Menu</span></a>
  </header>
  <!-- End header -->
<!-- search
        ================================================== -->
        <div class="s-search">

            <div class="search-block">
    
                <form role="search" method="get" class="search-form" action="/blog/search.php">
  <label>
    <span class="hide-content">Search for:</span>
    <input type="search" class="search-field" placeholder="Type Keywords" value="" name="q" title="Search for:" autocomplete="off">
  </label>
  <input type="submit" class="search-submit" value="Search">
</form>
    
                <a href="#0" title="Close Search" class="search-close">Close</a>
    
            </div>  <!-- end search-block -->

            <!-- search modal trigger -->
            <a href="#0" class="search-trigger">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" style="fill:rgba(0, 0, 0, 1);transform:;-ms-filter:"><path d="M10,18c1.846,0,3.543-0.635,4.897-1.688l4.396,4.396l1.414-1.414l-4.396-4.396C17.365,13.543,18,11.846,18,10 c0-4.411-3.589-8-8-8s-8,3.589-8,8S5.589,18,10,18z M10,4c3.309,0,6,2.691,6,6s-2.691,6-6,6s-6-2.691-6-6S6.691,4,10,4z"></path></svg>
                <span>Search</span>
            </a>
            <span class="search-line"></span>

        </div> <!-- end s-search -->
