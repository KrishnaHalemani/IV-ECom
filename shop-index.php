<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function fe_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$db = get_db_connection();

function normalize_home_section_key(string $name): string
{
    $key = strtolower(trim($name));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
    $key = trim($key, '_');

    if ($key === 'products' || $key === 'home' || $key === 'home_products') {
        return 'products_from_admin';
    }
    if ($key === 'category_sidebar') {
        return 'categories_sidebar';
    }
    if ($key === 'new' || $key === 'new_arrival') {
        return 'new_arrivals';
    }

    return $key;
}

function display_home_section_title(string $sectionName, string $fallback): string
{
    $value = trim($sectionName);
    if ($value === '') {
        return $fallback;
    }

    $compact = strtolower(preg_replace('/[^a-z0-9]+/', '_', $value) ?? '');
    $known = ['hero', 'products_from_admin', 'new_arrivals', 'featured', 'categories_sidebar'];
    if (in_array($compact, $known, true)) {
        return $fallback;
    }

    return ucwords(str_replace('_', ' ', $value));
}

$sectionDefaults = [
    'hero' => ['title' => 'Hero', 'enabled' => true, 'order' => 1],
    'products_from_admin' => ['title' => 'Products From Admin', 'enabled' => true, 'order' => 2],
    'new_arrivals' => ['title' => 'New Arrivals', 'enabled' => true, 'order' => 3],
    'featured' => ['title' => 'Featured', 'enabled' => true, 'order' => 4],
    'categories_sidebar' => ['title' => 'Categories', 'enabled' => true, 'order' => 5],
];

$sectionConfig = $sectionDefaults;
$sectionOrder = [];
$sectionsResult = $db->query('SELECT section_name, is_enabled, display_order FROM homepage_sections ORDER BY display_order ASC, id ASC');
if ($sectionsResult instanceof mysqli_result) {
    while ($row = $sectionsResult->fetch_assoc()) {
        $rawName = (string) ($row['section_name'] ?? '');
        $key = normalize_home_section_key($rawName);
        if (!isset($sectionConfig[$key])) {
            continue;
        }
        $sectionConfig[$key]['enabled'] = (int) ($row['is_enabled'] ?? 0) === 1;
        $sectionConfig[$key]['order'] = (int) ($row['display_order'] ?? $sectionConfig[$key]['order']);
        $sectionConfig[$key]['title'] = display_home_section_title($rawName, $sectionConfig[$key]['title']);
        $sectionOrder[] = $key;
    }
    $sectionsResult->free();
}

if ($sectionOrder === []) {
    $sectionOrder = array_keys($sectionConfig);
}

usort($sectionOrder, static function (string $a, string $b) use ($sectionConfig): int {
    $orderA = (int) ($sectionConfig[$a]['order'] ?? 9999);
    $orderB = (int) ($sectionConfig[$b]['order'] ?? 9999);
    if ($orderA === $orderB) {
        return strcmp($a, $b);
    }
    return $orderA <=> $orderB;
});
$sectionOrder = array_values(array_unique($sectionOrder));

$heroSlides = [];
$heroResult = $db->query(
    "SELECT id, title, subtitle, button_text, button_link, image
     FROM hero_sections
     WHERE is_active = 1
     ORDER BY sort_order ASC, id DESC"
);
if ($heroResult instanceof mysqli_result) {
    while ($row = $heroResult->fetch_assoc()) {
        $heroSlides[] = $row;
    }
    $heroResult->free();
}

if ($heroSlides === []) {
    $heroSlides = [
        [
            'id' => 1,
            'title' => 'Tones of Shop UI Features Designed',
            'subtitle' => 'Lorem ipsum dolor sit amet constectetuer diam adipiscing elit euismod ut laoreet dolore.',
            'button_text' => 'Shop Now',
            'button_link' => '#featured-products',
            'image' => 'assets/pages/img/shop-slider/slide1/bg.jpg',
        ],
        [
            'id' => 2,
            'title' => 'Unlimited Layout Options',
            'subtitle' => 'Build your storefront quickly with reusable components and production-ready layout blocks.',
            'button_text' => 'Shop Now',
            'button_link' => '#featured-products',
            'image' => 'assets/pages/img/shop-slider/slide2/bg.jpg',
        ],
    ];
}

$selectedCategorySlug = trim((string) ($_GET['category'] ?? ''));
$selectedCategoryId = 0;
$categories = [];
$categoriesResult = $db->query('SELECT id, name, slug, is_active FROM categories WHERE is_active = 1 ORDER BY name ASC');
if ($categoriesResult instanceof mysqli_result) {
    while ($row = $categoriesResult->fetch_assoc()) {
        $categories[] = $row;
        if ($selectedCategorySlug !== '' && $selectedCategorySlug === (string) $row['slug']) {
            $selectedCategoryId = (int) $row['id'];
        }
    }
    $categoriesResult->free();
}

$allProducts = [];
$allProductSql = "SELECT p.id, p.name, p.image_path, p.price, p.stock_qty, p.category_id, p.is_featured, p.is_new, p.display_section, c.name AS category_name
                  FROM products p
                  LEFT JOIN categories c ON c.id = p.category_id
                  WHERE p.is_active = 1
                  ORDER BY p.id DESC
                  LIMIT 120";
$allProductResult = $db->query($allProductSql);
if ($allProductResult instanceof mysqli_result) {
    while ($row = $allProductResult->fetch_assoc()) {
        $allProducts[] = $row;
    }
    $allProductResult->free();
}

if ($allProducts === []) {
    $fallbackSql = 'SELECT id, name, image_path, price, stock_qty, category_id FROM products WHERE is_active = 1 ORDER BY id DESC LIMIT 20';
    $fallbackResult = $db->query($fallbackSql);
    if ($fallbackResult instanceof mysqli_result) {
        while ($row = $fallbackResult->fetch_assoc()) {
            $row['display_section'] = 'home';
            $row['is_featured'] = 0;
            $row['is_new'] = 0;
            $row['category_name'] = 'General';
            $allProducts[] = $row;
        }
        $fallbackResult->free();
    }
}

$products = $allProducts;
if ($selectedCategoryId > 0) {
    $products = array_values(array_filter($allProducts, static function (array $product) use ($selectedCategoryId): bool {
        return (int) ($product['category_id'] ?? 0) === $selectedCategoryId;
    }));
}

$homeProducts = [];
$newArrivalProducts = [];
$featuredProducts = [];
$newSeen = [];
$featuredSeen = [];

foreach ($products as $product) {
    $displaySection = (string) ($product['display_section'] ?? 'home');
    $productId = (int) ($product['id'] ?? 0);
    if ($productId <= 0 || $displaySection === 'none') {
        continue;
    }

    if ($displaySection !== 'new_arrivals' && $displaySection !== 'featured') {
        $homeProducts[] = $product;
    }
}

foreach ($allProducts as $product) {
    $displaySection = (string) ($product['display_section'] ?? 'home');
    $productId = (int) ($product['id'] ?? 0);
    if ($productId <= 0 || $displaySection === 'none') {
        continue;
    }

    if ($displaySection === 'new_arrivals') {
        $newArrivalProducts[] = $product;
        $newSeen[$productId] = true;
        continue;
    }

    if ($displaySection === 'featured') {
        $featuredProducts[] = $product;
        $featuredSeen[$productId] = true;
    }
}

foreach ($allProducts as $product) {
    $productId = (int) ($product['id'] ?? 0);
    if ($productId <= 0) {
        continue;
    }

    if ((int) ($product['is_new'] ?? 0) === 1 && !isset($newSeen[$productId])) {
        $newArrivalProducts[] = $product;
        $newSeen[$productId] = true;
    }

    if ((int) ($product['is_featured'] ?? 0) === 1 && !isset($featuredSeen[$productId])) {
        $featuredProducts[] = $product;
        $featuredSeen[$productId] = true;
    }
}
?>
<!DOCTYPE html>
<!--
Template: Metronic Frontend Freebie - Responsive HTML Template Based On Twitter Bootstrap 3.3.4
Version: 1.0.0
Author: KeenThemes
Website: http://www.keenthemes.com/
Contact: support@keenthemes.com
Follow: www.twitter.com/keenthemes
Like: www.facebook.com/keenthemes
Purchase Premium Metronic Admin Theme: http://themeforest.net/item/metronic-responsive-admin-dashboard-template/4021469?ref=keenthemes
-->
<!--[if IE 8]> <html lang="en" class="ie8 no-js"> <![endif]-->
<!--[if IE 9]> <html lang="en" class="ie9 no-js"> <![endif]-->
<!--[if !IE]><!-->
<html lang="en">
<!--<![endif]-->

<!-- Head BEGIN -->
<head>
  <meta charset="utf-8">
  <title>Metronic Shop UI</title>

  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">

  <meta content="Metronic Shop UI description" name="description">
  <meta content="Metronic Shop UI keywords" name="keywords">
  <meta content="keenthemes" name="author">

  <meta property="og:site_name" content="-CUSTOMER VALUE-">
  <meta property="og:title" content="-CUSTOMER VALUE-">
  <meta property="og:description" content="-CUSTOMER VALUE-">
  <meta property="og:type" content="website">
  <meta property="og:image" content="-CUSTOMER VALUE-"><!-- link to image for socio -->
  <meta property="og:url" content="-CUSTOMER VALUE-">

  <link rel="shortcut icon" href="favicon.ico">

  <!-- Fonts START -->
  <link href="http://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|PT+Sans+Narrow|Source+Sans+Pro:200,300,400,600,700,900&amp;subset=all" rel="stylesheet" type="text/css">
  <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:200,300,400,600,700,900&amp;subset=all" rel="stylesheet" type="text/css"><!--- fonts for slider on the index page -->  
  <!-- Fonts END -->

  <!-- Global styles START -->          
  <link href="assets/plugins/font-awesome/css/font-awesome.min.css" rel="stylesheet">
  <link href="assets/plugins/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <!-- Global styles END --> 
   
  <!-- Page level plugin styles START -->
  <link href="assets/pages/css/animate.css" rel="stylesheet">
  <link href="assets/plugins/fancybox/source/jquery.fancybox.css" rel="stylesheet">
  <link href="assets/plugins/owl.carousel/assets/owl.carousel.css" rel="stylesheet">
  <!-- Page level plugin styles END -->

  <!-- Theme styles START -->
  <link href="assets/pages/css/components.css" rel="stylesheet">
  <link href="assets/pages/css/slider.css" rel="stylesheet">
  <link href="assets/pages/css/style-shop.css" rel="stylesheet" type="text/css">
  <link href="assets/corporate/css/style.css" rel="stylesheet">
  <link href="assets/corporate/css/style-responsive.css" rel="stylesheet">
  <link href="assets/corporate/css/themes/red.css" rel="stylesheet" id="style-color">
  <link href="assets/corporate/css/custom.css" rel="stylesheet">
  <link href="assets/pages/css/shop-modern.css" rel="stylesheet">
  <!-- Theme styles END -->
</head>
<!-- Head END -->

<!-- Body BEGIN -->
<body class="ecommerce">
    <!-- BEGIN STYLE CUSTOMIZER -->
    <div class="color-panel hidden-sm">
      <div class="color-mode-icons icon-color"></div>
      <div class="color-mode-icons icon-color-close"></div>
      <div class="color-mode">
        <p>THEME COLOR</p>
        <ul class="inline">
          <li class="color-red current color-default" data-style="red"></li>
          <li class="color-blue" data-style="blue"></li>
          <li class="color-green" data-style="green"></li>
          <li class="color-orange" data-style="orange"></li>
          <li class="color-gray" data-style="gray"></li>
          <li class="color-turquoise" data-style="turquoise"></li>
        </ul>
      </div>
    </div>
    <!-- END BEGIN STYLE CUSTOMIZER --> 

    <!-- BEGIN TOP BAR -->
    <div class="pre-header">
        <div class="container">
            <div class="row">
                <!-- BEGIN TOP BAR LEFT PART -->
                <div class="col-md-6 col-sm-6 additional-shop-info">
                    <ul class="list-unstyled list-inline">
                        <li><i class="fa fa-phone"></i><span>+1 456 6717</span></li>
                        <!-- BEGIN CURRENCIES -->
                        <li class="shop-currencies">
                            <a href="javascript:void(0);">€</a>
                            <a href="javascript:void(0);">£</a>
                            <a href="javascript:void(0);" class="current">$</a>
                            <a href="javascript:void(0);" class="current">INR</a>
                        </li>
                        <!-- END CURRENCIES -->
                        <!-- BEGIN LANGS -->
                        <li class="langs-block">
                            <a href="javascript:void(0);" class="current">English </a>
                            <div class="langs-block-others-wrapper"><div class="langs-block-others">
                              <a href="javascript:void(0);">French</a>
                              <a href="javascript:void(0);">Germany</a>
                              <a href="javascript:void(0);">Turkish</a>
                            </div></div>
                        </li>
                        <!-- END LANGS -->
                    </ul>
                </div>
                <!-- END TOP BAR LEFT PART -->
                <!-- BEGIN TOP BAR MENU -->
                <div class="col-md-6 col-sm-6 additional-nav">
                    <ul class="list-unstyled list-inline pull-right">
                        <li><a href="shop-account.php">My Account</a></li>
                        <li><a href="shop-wishlist.php">My Wishlist</a></li>
                        <li><a href="shop-checkout.php">Checkout</a></li>
                        <li><a href="google-login.php">Log In</a></li>
                    </ul>
                </div>
                <!-- END TOP BAR MENU -->
            </div>
        </div>        
    </div>
    <!-- END TOP BAR -->

    <!-- BEGIN HEADER -->
    <div class="header">
      <div class="container">
        <a class="site-logo" href="shop-index.php"><img src="assets/corporate/img/logos/BattleRockLogo.jpeg" alt="Metronic Shop UI"></a>

        <a href="javascript:void(0);" class="mobi-toggler"><i class="fa fa-bars"></i></a>

        <!-- BEGIN CART -->
        <div class="top-cart-block">
          <div class="top-cart-info">
            <a href="javascript:void(0);" class="top-cart-info-count">3 items</a>
            <a href="javascript:void(0);" class="top-cart-info-value">$1260</a>
            <a href="javascript:void(0);" class="top-cart-info-value">INR 1260</a>
          </div>
          <i class="fa fa-shopping-cart"></i>
                        
          <div class="top-cart-content-wrapper">
            <div class="top-cart-content">
              <ul class="scroller" style="height: 250px;">
                <li>
                  <a href="shop-item.php"><img src="assets/pages/img/cart-img.jpg" alt="Rolex Classic Watch" width="37" height="34"></a>
                  <span class="cart-content-count">x 1</span>
                  <strong><a href="shop-item.php">Rolex Classic Watch</a></strong>
                  <em>$1230</em>
                  <a href="javascript:void(0);" class="del-goods">&nbsp;</a>
                </li>
                <li>
                  <a href="shop-item.php"><img src="assets/pages/img/cart-img.jpg" alt="Rolex Classic Watch" width="37" height="34"></a>
                  <span class="cart-content-count">x 1</span>
                  <strong><a href="shop-item.php">Rolex Classic Watch</a></strong>
                  <em>$1230</em>
                  <a href="javascript:void(0);" class="del-goods">&nbsp;</a>
                </li>
                <li>
                  <a href="shop-item.php"><img src="assets/pages/img/cart-img.jpg" alt="Rolex Classic Watch" width="37" height="34"></a>
                  <span class="cart-content-count">x 1</span>
                  <strong><a href="shop-item.php">Rolex Classic Watch</a></strong>
                  <em>$1230</em>
                  <a href="javascript:void(0);" class="del-goods">&nbsp;</a>
                </li>
                <li>
                  <a href="shop-item.php"><img src="assets/pages/img/cart-img.jpg" alt="Rolex Classic Watch" width="37" height="34"></a>
                  <span class="cart-content-count">x 1</span>
                  <strong><a href="shop-item.php">Rolex Classic Watch</a></strong>
                  <em>$1230</em>
                  <a href="javascript:void(0);" class="del-goods">&nbsp;</a>
                </li>
                <li>
                  <a href="shop-item.php"><img src="assets/pages/img/cart-img.jpg" alt="Rolex Classic Watch" width="37" height="34"></a>
                  <span class="cart-content-count">x 1</span>
                  <strong><a href="shop-item.php">Rolex Classic Watch</a></strong>
                  <em>$1230</em>
                  <a href="javascript:void(0);" class="del-goods">&nbsp;</a>
                </li>
                <li>
                  <a href="shop-item.php"><img src="assets/pages/img/cart-img.jpg" alt="Rolex Classic Watch" width="37" height="34"></a>
                  <span class="cart-content-count">x 1</span>
                  <strong><a href="shop-item.php">Rolex Classic Watch</a></strong>
                  <em>$1230</em>
                  <a href="javascript:void(0);" class="del-goods">&nbsp;</a>
                </li>
                <li>
                  <a href="shop-item.php"><img src="assets/pages/img/cart-img.jpg" alt="Rolex Classic Watch" width="37" height="34"></a>
                  <span class="cart-content-count">x 1</span>
                  <strong><a href="shop-item.php">Rolex Classic Watch</a></strong>
                  <em>$1230</em>
                  <a href="javascript:void(0);" class="del-goods">&nbsp;</a>
                </li>
                <li>
                  <a href="shop-item.php"><img src="assets/pages/img/cart-img.jpg" alt="Rolex Classic Watch" width="37" height="34"></a>
                  <span class="cart-content-count">x 1</span>
                  <strong><a href="shop-item.php">Rolex Classic Watch</a></strong>
                  <em>$1230</em>
                  <a href="javascript:void(0);" class="del-goods">&nbsp;</a>
                </li>
              </ul>
              <div class="text-right">
                <a href="shop-shopping-cart.php" class="btn btn-default">View Cart</a>
                <a href="shop-checkout.php" class="btn btn-primary">Checkout</a>
              </div>
            </div>
          </div>            
        </div>
        <!--END CART -->

        <!-- BEGIN NAVIGATION -->
        <div class="header-navigation">
          <ul>
            <li class="dropdown">
              <a class="dropdown-toggle" data-toggle="dropdown" data-target="#" href="javascript:;">
                Woman 
                
              </a>
                
              <!-- BEGIN DROPDOWN MENU -->
              <ul class="dropdown-menu">
                <li class="dropdown-submenu">
                  <a href="shop-product-list.php">Hi Tops <i class="fa fa-angle-right"></i></a>
                  <ul class="dropdown-menu" role="menu">
                    <li><a href="shop-product-list.php">Second Level Link</a></li>
                    <li><a href="shop-product-list.php">Second Level Link</a></li>
                    <li class="dropdown-submenu">
                      <a class="dropdown-toggle" data-toggle="dropdown" data-target="#" href="javascript:;">
                        Second Level Link 
                        <i class="fa fa-angle-right"></i>
                      </a>
                      <ul class="dropdown-menu">
                        <li><a href="shop-product-list.php">Third Level Link</a></li>
                        <li><a href="shop-product-list.php">Third Level Link</a></li>
                        <li><a href="shop-product-list.php">Third Level Link</a></li>
                      </ul>
                    </li>
                  </ul>
                </li>
                <li><a href="shop-product-list.php">Running Shoes</a></li>
                <li><a href="shop-product-list.php">Jackets and Coats</a></li>
              </ul>
              <!-- END DROPDOWN MENU -->
            </li>
            <li class="dropdown dropdown-megamenu">
              <a class="dropdown-toggle" data-toggle="dropdown" data-target="#" href="javascript:;">
                Man
                
              </a>
              <ul class="dropdown-menu">
                <li>
                  <div class="header-navigation-content">
                    <div class="row">
                      <div class="col-md-4 header-navigation-col">
                        <h4>Footwear</h4>
                        <ul>
                          <li><a href="shop-product-list.php">Astro Trainers</a></li>
                          <li><a href="shop-product-list.php">Basketball Shoes</a></li>
                          <li><a href="shop-product-list.php">Boots</a></li>
                          <li><a href="shop-product-list.php">Canvas Shoes</a></li>
                          <li><a href="shop-product-list.php">Football Boots</a></li>
                          <li><a href="shop-product-list.php">Golf Shoes</a></li>
                          <li><a href="shop-product-list.php">Hi Tops</a></li>
                          <li><a href="shop-product-list.php">Indoor and Court Trainers</a></li>
                        </ul>
                      </div>
                      <div class="col-md-4 header-navigation-col">
                        <h4>Clothing</h4>
                        <ul>
                          <li><a href="shop-product-list.php">Base Layer</a></li>
                          <li><a href="shop-product-list.php">Character</a></li>
                          <li><a href="shop-product-list.php">Chinos</a></li>
                          <li><a href="shop-product-list.php">Combats</a></li>
                          <li><a href="shop-product-list.php">Cricket Clothing</a></li>
                          <li><a href="shop-product-list.php">Fleeces</a></li>
                          <li><a href="shop-product-list.php">Gilets</a></li>
                          <li><a href="shop-product-list.php">Golf Tops</a></li>
                        </ul>
                      </div>
                      <div class="col-md-4 header-navigation-col">
                        <h4>Accessories</h4>
                        <ul>
                          <li><a href="shop-product-list.php">Belts</a></li>
                          <li><a href="shop-product-list.php">Caps</a></li>
                          <li><a href="shop-product-list.php">Gloves, Hats and Scarves</a></li>
                        </ul>

                        <h4>Clearance</h4>
                        <ul>
                          <li><a href="shop-product-list.php">Jackets</a></li>
                          <li><a href="shop-product-list.php">Bottoms</a></li>
                        </ul>
                      </div>
                      <div class="col-md-12 nav-brands">
                        <ul>
                          <li><a href="shop-product-list.php"><img title="esprit" alt="esprit" src="assets/pages/img/brands/esprit.jpg"></a></li>
                          <li><a href="shop-product-list.php"><img title="gap" alt="gap" src="assets/pages/img/brands/gap.jpg"></a></li>
                          <li><a href="shop-product-list.php"><img title="next" alt="next" src="assets/pages/img/brands/next.jpg"></a></li>
                          <li><a href="shop-product-list.php"><img title="puma" alt="puma" src="assets/pages/img/brands/puma.jpg"></a></li>
                          <li><a href="shop-product-list.php"><img title="zara" alt="zara" src="assets/pages/img/brands/zara.jpg"></a></li>
                        </ul>
                      </div>
                    </div>
                  </div>
                </li>
              </ul>
            </li>
            <li><a href="shop-item.php">Kids</a></li>
            <li class="dropdown dropdown100 nav-catalogue">
              <a class="dropdown-toggle" data-toggle="dropdown" data-target="#" href="javascript:;">
                New
                
              </a>
              <ul class="dropdown-menu">
                <li>
                  <div class="header-navigation-content">
                    <div class="row">
                      <div class="col-md-3 col-sm-4 col-xs-6">
                        <div class="product-item">
                          <div class="pi-img-wrapper">
                            <a href="shop-item.php"><img src="assets/pages/img/products/model4.jpg" class="img-responsive" alt="Berry Lace Dress"></a>
                          </div>
                          <h3><a href="shop-item.php">Berry Lace Dress</a></h3>
                          <div class="pi-price">$29.00</div>
                          <a href="javascript:;" class="btn btn-default add2cart">Add to cart</a>
                        </div>
                      </div>
                      <div class="col-md-3 col-sm-4 col-xs-6">
                        <div class="product-item">
                          <div class="pi-img-wrapper">
                            <a href="shop-item.php"><img src="assets/pages/img/products/model3.jpg" class="img-responsive" alt="Berry Lace Dress"></a>
                          </div>
                          <h3><a href="shop-item.php">Berry Lace Dress</a></h3>
                          <div class="pi-price">$29.00</div>
                          <a href="javascript:;" class="btn btn-default add2cart">Add to cart</a>
                        </div>
                      </div>
                      <div class="col-md-3 col-sm-4 col-xs-6">
                        <div class="product-item">
                          <div class="pi-img-wrapper">
                            <a href="shop-item.php"><img src="assets/pages/img/products/model7.jpg" class="img-responsive" alt="Berry Lace Dress"></a>
                          </div>
                          <h3><a href="shop-item.php">Berry Lace Dress</a></h3>
                          <div class="pi-price">$29.00</div>
                          <a href="javascript:;" class="btn btn-default add2cart">Add to cart</a>
                        </div>
                      </div>
                      <div class="col-md-3 col-sm-4 col-xs-6">
                        <div class="product-item">
                          <div class="pi-img-wrapper">
                            <a href="shop-item.php"><img src="assets/pages/img/products/model4.jpg" class="img-responsive" alt="Berry Lace Dress"></a>
                          </div>
                          <h3><a href="shop-item.php">Berry Lace Dress</a></h3>
                          <div class="pi-price">$29.00</div>
                          <a href="javascript:;" class="btn btn-default add2cart">Add to cart</a>
                        </div>
                      </div>
                    </div>
                  </div>
                </li>
              </ul>
            </li>
            <li class="dropdown">
              <a class="dropdown-toggle" data-toggle="dropdown" data-target="#" href="javascript:;">
                Pages 
                
              </a>
                
              <ul class="dropdown-menu">
                <li class="active"><a href="shop-index.php">Home Default</a></li>
                <li><a href="shop-index-header-fix.php">Home Header Fixed</a></li>
                <li><a href="shop-index-light-footer.php">Home Light Footer</a></li>
                <li><a href="shop-product-list.php">Product List</a></li>
                <li><a href="shop-search-result.php">Search Result</a></li>
                <li><a href="shop-item.php">Product Page</a></li>
                <li><a href="shop-shopping-cart-null.php">Shopping Cart (Null Cart)</a></li>
                <li><a href="shop-shopping-cart.php">Shopping Cart</a></li>
                <li><a href="shop-checkout.php">Checkout</a></li>
                <li><a href="shop-about.php">About</a></li>
                <li><a href="shop-contacts.php">Contacts</a></li>
                <li><a href="shop-account.php">My account</a></li>
                <li><a href="shop-wishlist.php">My Wish List</a></li>
                <li><a href="shop-goods-compare.php">Product Comparison</a></li>
                <li><a href="shop-standart-forms.php">Standart Forms</a></li>
                <li><a href="shop-faq.php">FAQ</a></li>
                <li><a href="shop-privacy-policy.php">Privacy Policy</a></li>
                <li><a href="shop-terms-conditions-page.php">Terms &amp; Conditions</a></li>
              </ul>
            </li>
            
            
            <li><a href="http://themeforest.net/item/metronic-responsive-admin-dashboard-template/4021469?ref=keenthemes&amp;utm_source=download&amp;utm_medium=banner&amp;utm_campaign=metronic_frontend_freebie" target="_blank">Admin theme</a></li>

            <!-- BEGIN TOP SEARCH -->
            <li class="menu-search">
              <span class="sep"></span>
              <i class="fa fa-search search-btn"></i>
              <div class="search-box">
                <form action="form-submit.php" method="post">
                  <div class="input-group">
                    <input type="text" placeholder="Search" class="form-control" name="search">
                    <span class="input-group-btn">
                      <button class="btn btn-primary" type="submit">Search</button>
                    </span>
                  </div>
                </form>
              </div> 
            </li>
            <!-- END TOP SEARCH -->
          </ul>
        </div>
        <!-- END NAVIGATION -->
      </div>
    </div>
    <!-- Header END -->

    <?php if (!empty($sectionConfig['hero']['enabled'])): ?>
    <!-- BEGIN SLIDER -->
    <div class="page-slider margin-bottom-35">
        <div id="carousel-example-generic" class="carousel slide carousel-slider">
            <!-- Indicators -->
            <ol class="carousel-indicators">
                <?php foreach ($heroSlides as $i => $slide): ?>
                  <li data-target="#carousel-example-generic" data-slide-to="<?php echo (int) $i; ?>" class="<?php echo $i === 0 ? 'active' : ''; ?>"></li>
                <?php endforeach; ?>
            </ol>

            <!-- Wrapper for slides -->
            <div class="carousel-inner" role="listbox">
                <?php foreach ($heroSlides as $i => $slide): ?>
                  <?php
                  $imagePath = trim((string) ($slide['image'] ?? ($slide['image_path'] ?? '')));
                  if ($imagePath === '') {
                      $imagePath = 'assets/pages/img/shop-slider/slide1/bg.jpg';
                  }
                  $title = trim((string) ($slide['title'] ?? ''));
                  $subtitle = trim((string) ($slide['subtitle'] ?? ''));
                  $description = trim((string) ($slide['description'] ?? ''));
                  $ctaText = trim((string) ($slide['button_text'] ?? ($slide['cta_text'] ?? 'Shop Now')));
                  $ctaLink = trim((string) ($slide['button_link'] ?? ($slide['cta_link'] ?? '#featured-products')));
                  ?>
                  <div class="item carousel-item-admin <?php echo $i === 0 ? 'active' : ''; ?>" style="background: url('<?php echo fe_h($imagePath); ?>') center center no-repeat; background-size: cover;">
                    <div class="container">
                      <div class="carousel-position-four text-center">
                        <?php if ($title !== ''): ?>
                          <h2 class="margin-bottom-20 animate-delay carousel-title-v3 border-bottom-title text-uppercase" data-animation="animated fadeInDown">
                            <?php echo nl2br(fe_h($title)); ?>
                          </h2>
                        <?php endif; ?>
                        <?php if ($subtitle !== ''): ?>
                          <p class="carousel-subtitle-v3 margin-bottom-15" data-animation="animated fadeInDown"><?php echo fe_h($subtitle); ?></p>
                        <?php endif; ?>
                        <?php if ($description !== ''): ?>
                          <p class="carousel-subtitle-v2" data-animation="animated fadeInUp"><?php echo nl2br(fe_h($description)); ?></p>
                        <?php endif; ?>
                        <a class="carousel-btn" href="<?php echo fe_h($ctaLink !== '' ? $ctaLink : '#featured-products'); ?>" data-shop-cta="true" data-animation="animated fadeInUp"><?php echo fe_h($ctaText !== '' ? $ctaText : 'Shop Now'); ?></a>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
            </div>

            <!-- Controls -->
            <a class="left carousel-control carousel-control-shop" href="#carousel-example-generic" role="button" data-slide="prev">
                <i class="fa fa-angle-left" aria-hidden="true"></i>
            </a>
            <a class="right carousel-control carousel-control-shop" href="#carousel-example-generic" role="button" data-slide="next">
                <i class="fa fa-angle-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
    <!-- END SLIDER -->
    <?php endif; ?>

    <div class="main shop-main-content">
      <div class="container">
        <?php
          $hasRenderedMainSection = false;
          $homeRendered = false;
          $categoriesEnabled = (bool) ($sectionConfig['categories_sidebar']['enabled'] ?? true);
          $productsSectionTitle = (string) ($sectionConfig['products_from_admin']['title'] ?? 'Products From Admin');
          $newArrivalsTitle = (string) ($sectionConfig['new_arrivals']['title'] ?? 'New Arrivals');
          $featuredTitle = (string) ($sectionConfig['featured']['title'] ?? 'Featured');
        ?>

        <?php foreach ($sectionOrder as $sectionKey): ?>
          <?php if (empty($sectionConfig[$sectionKey]['enabled'])): ?>
            <?php continue; ?>
          <?php endif; ?>

          <?php if ($sectionKey === 'products_from_admin' || ($sectionKey === 'categories_sidebar' && empty($sectionConfig['products_from_admin']['enabled']))): ?>
            <?php if ($homeRendered): ?>
              <?php continue; ?>
            <?php endif; ?>
            <?php $homeRendered = true; $hasRenderedMainSection = true; ?>
            <div class="row margin-bottom-40 featured-products-section" id="featured-products">
              <?php if ($categoriesEnabled): ?>
                <div class="sidebar col-md-3 col-sm-4">
                  <ul class="list-group margin-bottom-25 sidebar-menu">
                    <li class="list-group-item clearfix<?php echo $selectedCategorySlug === '' ? ' active' : ''; ?>">
                      <a href="shop-index.php#featured-products"><i class="fa fa-angle-right"></i> All Categories</a>
                    </li>
                    <?php foreach ($categories as $category): ?>
                      <?php
                        $catSlug = (string) ($category['slug'] ?? '');
                        $catName = trim((string) ($category['name'] ?? 'Category'));
                        if ($catSlug === '' || $catName === '') {
                            continue;
                        }
                      ?>
                      <li class="list-group-item clearfix<?php echo $selectedCategorySlug === $catSlug ? ' active' : ''; ?>">
                        <a href="shop-index.php?category=<?php echo rawurlencode($catSlug); ?>#featured-products"><i class="fa fa-angle-right"></i> <?php echo fe_h($catName); ?></a>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                  <div class="sidebar-filter"></div>
                </div>
              <?php endif; ?>

              <div class="<?php echo $categoriesEnabled ? 'col-md-9 col-sm-8' : 'col-md-12'; ?> sale-product">
                <h2><?php echo fe_h($productsSectionTitle); ?></h2>
                <?php if ($homeProducts !== []): ?>
                  <div class="row product-list js-product-grid js-catalog-grid">
                    <?php foreach ($homeProducts as $product): ?>
                      <?php
                        $img = trim((string) ($product['image_path'] ?? ''));
                        if ($img === '') {
                            $img = 'assets/pages/img/products/model1.jpg';
                        }
                        $categoryName = trim((string) ($product['category_name'] ?? 'General'));
                        if ($categoryName === '') {
                            $categoryName = 'General';
                        }
                        $productId = (int) ($product['id'] ?? 0);
                      ?>
                      <div class="col-md-4 col-sm-6 col-xs-12">
                        <div class="product-item"
                          data-product-id="<?php echo $productId; ?>"
                          data-product-price="<?php echo number_format((float) ($product['price'] ?? 0), 2, '.', ''); ?>"
                          data-stock="<?php echo (int) ($product['stock_qty'] ?? 0); ?>"
                          data-category="<?php echo fe_h($categoryName); ?>">
                          <div class="pi-img-wrapper">
                            <img src="<?php echo fe_h($img); ?>" class="img-responsive" alt="<?php echo fe_h((string) ($product['name'] ?? 'Product')); ?>" loading="lazy" decoding="async">
                            <div>
                              <a href="<?php echo fe_h($img); ?>" class="btn btn-default fancybox-button">Zoom</a>
                              <a href="shop-item.php?id=<?php echo $productId; ?>" class="btn btn-default js-quick-view">Quick View</a>
                            </div>
                          </div>
                          <h3><a href="shop-item.php?id=<?php echo $productId; ?>"><?php echo fe_h((string) ($product['name'] ?? 'Product')); ?></a></h3>
                          <div class="pi-price">$<?php echo number_format((float) ($product['price'] ?? 0), 2); ?></div>
                          <p class="product-meta"><?php echo fe_h($categoryName); ?> | <?php echo (int) ($product['stock_qty'] ?? 0) > 0 ? 'In Stock' : 'Out of Stock'; ?></p>
                          <button type="button" class="btn btn-primary js-add-to-cart" data-product-id="<?php echo $productId; ?>">Add to cart</button>
                          <a href="shop-item.php?id=<?php echo $productId; ?>" class="btn btn-default">Details</a>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="shop-filter-empty" style="display:block;">No products found for the selected category.</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($sectionKey === 'new_arrivals'): ?>
            <?php $hasRenderedMainSection = true; ?>
            <div class="row margin-bottom-40" id="new-arrivals">
              <div class="col-md-12 sale-product">
                <h2><?php echo fe_h($newArrivalsTitle); ?></h2>
                <?php if ($newArrivalProducts !== []): ?>
                  <div class="row product-list">
                    <?php foreach ($newArrivalProducts as $product): ?>
                      <?php
                        $img = trim((string) ($product['image_path'] ?? ''));
                        if ($img === '') {
                            $img = 'assets/pages/img/products/model2.jpg';
                        }
                        $categoryName = trim((string) ($product['category_name'] ?? 'General'));
                        if ($categoryName === '') {
                            $categoryName = 'General';
                        }
                        $productId = (int) ($product['id'] ?? 0);
                      ?>
                      <div class="col-md-3 col-sm-6 col-xs-12">
                        <div class="product-item"
                          data-product-id="<?php echo $productId; ?>"
                          data-product-price="<?php echo number_format((float) ($product['price'] ?? 0), 2, '.', ''); ?>"
                          data-stock="<?php echo (int) ($product['stock_qty'] ?? 0); ?>"
                          data-category="<?php echo fe_h($categoryName); ?>">
                          <div class="pi-img-wrapper">
                            <img src="<?php echo fe_h($img); ?>" class="img-responsive" alt="<?php echo fe_h((string) ($product['name'] ?? 'Product')); ?>" loading="lazy" decoding="async">
                            <div>
                              <a href="<?php echo fe_h($img); ?>" class="btn btn-default fancybox-button">Zoom</a>
                              <a href="shop-item.php?id=<?php echo $productId; ?>" class="btn btn-default js-quick-view">Quick View</a>
                            </div>
                          </div>
                          <h3><a href="shop-item.php?id=<?php echo $productId; ?>"><?php echo fe_h((string) ($product['name'] ?? 'Product')); ?></a></h3>
                          <div class="pi-price">$<?php echo number_format((float) ($product['price'] ?? 0), 2); ?></div>
                          <button type="button" class="btn btn-primary js-add-to-cart" data-product-id="<?php echo $productId; ?>">Add to cart</button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="shop-filter-empty" style="display:block;">No new arrivals available right now.</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($sectionKey === 'featured'): ?>
            <?php $hasRenderedMainSection = true; ?>
            <div class="row margin-bottom-35" id="featured-items">
              <div class="col-md-12 sale-product">
                <h2><?php echo fe_h($featuredTitle); ?></h2>
                <?php if ($featuredProducts !== []): ?>
                  <div class="row product-list">
                    <?php foreach ($featuredProducts as $product): ?>
                      <?php
                        $img = trim((string) ($product['image_path'] ?? ''));
                        if ($img === '') {
                            $img = 'assets/pages/img/products/model3.jpg';
                        }
                        $categoryName = trim((string) ($product['category_name'] ?? 'General'));
                        if ($categoryName === '') {
                            $categoryName = 'General';
                        }
                        $productId = (int) ($product['id'] ?? 0);
                      ?>
                      <div class="col-md-3 col-sm-6 col-xs-12">
                        <div class="product-item"
                          data-product-id="<?php echo $productId; ?>"
                          data-product-price="<?php echo number_format((float) ($product['price'] ?? 0), 2, '.', ''); ?>"
                          data-stock="<?php echo (int) ($product['stock_qty'] ?? 0); ?>"
                          data-category="<?php echo fe_h($categoryName); ?>">
                          <div class="pi-img-wrapper">
                            <img src="<?php echo fe_h($img); ?>" class="img-responsive" alt="<?php echo fe_h((string) ($product['name'] ?? 'Product')); ?>" loading="lazy" decoding="async">
                            <div>
                              <a href="<?php echo fe_h($img); ?>" class="btn btn-default fancybox-button">Zoom</a>
                              <a href="shop-item.php?id=<?php echo $productId; ?>" class="btn btn-default js-quick-view">Quick View</a>
                            </div>
                          </div>
                          <h3><a href="shop-item.php?id=<?php echo $productId; ?>"><?php echo fe_h((string) ($product['name'] ?? 'Product')); ?></a></h3>
                          <div class="pi-price">$<?php echo number_format((float) ($product['price'] ?? 0), 2); ?></div>
                          <button type="button" class="btn btn-primary js-add-to-cart" data-product-id="<?php echo $productId; ?>">Add to cart</button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="shop-filter-empty" style="display:block;">No featured products available right now.</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!$hasRenderedMainSection): ?>
          <div class="row margin-bottom-40">
            <div class="col-md-12">
              <div class="shop-filter-empty" style="display:block;">No homepage sections are enabled. Enable sections from Admin Controls.</div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- BEGIN BRANDS -->
    <div class="brands">
      <div class="container">
            <div class="owl-carousel owl-carousel6-brands">
              <a href="shop-product-list.php"><img src="assets/pages/img/brands/canon.jpg" alt="canon" title="canon"></a>
              <a href="shop-product-list.php"><img src="assets/pages/img/brands/esprit.jpg" alt="esprit" title="esprit"></a>
              <a href="shop-product-list.php"><img src="assets/pages/img/brands/gap.jpg" alt="gap" title="gap"></a>
              <a href="shop-product-list.php"><img src="assets/pages/img/brands/next.jpg" alt="next" title="next"></a>
              <a href="shop-product-list.php"><img src="assets/pages/img/brands/puma.jpg" alt="puma" title="puma"></a>
              <a href="shop-product-list.php"><img src="assets/pages/img/brands/zara.jpg" alt="zara" title="zara"></a>
              <a href="shop-product-list.php"><img src="assets/pages/img/brands/canon.jpg" alt="canon" title="canon"></a>
              <a href="shop-product-list.php"><img src="assets/pages/img/brands/esprit.jpg" alt="esprit" title="esprit"></a>
              <a href="shop-product-list.php"><img src="assets/pages/img/brands/gap.jpg" alt="gap" title="gap"></a>
              <a href="shop-product-list.php"><img src="assets/pages/img/brands/next.jpg" alt="next" title="next"></a>
              <a href="shop-product-list.php"><img src="assets/pages/img/brands/puma.jpg" alt="puma" title="puma"></a>
              <a href="shop-product-list.php"><img src="assets/pages/img/brands/zara.jpg" alt="zara" title="zara"></a>
            </div>
        </div>
    </div>
    <!-- END BRANDS -->

    <!-- BEGIN STEPS -->
    <div class="steps-block steps-block-red">
      <div class="container">
        <div class="row">
          <div class="col-md-4 steps-block-col">
            <i class="fa fa-truck"></i>
            <div>
              <h2>Free shipping</h2>
              <em>Express delivery withing 3 days</em>
            </div>
            <span>&nbsp;</span>
          </div>
          <div class="col-md-4 steps-block-col">
            <i class="fa fa-gift"></i>
            <div>
              <h2>Daily Gifts</h2>
              <em>3 Gifts daily for lucky customers</em>
            </div>
            <span>&nbsp;</span>
          </div>
          <div class="col-md-4 steps-block-col">
            <i class="fa fa-phone"></i>
            <div>
              <h2>477 505 8877</h2>
              <em>24/7 customer care available</em>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- END STEPS -->

    <!-- BEGIN PRE-FOOTER -->
    <div class="pre-footer">
      <div class="container">
        <div class="row">
          <!-- BEGIN BOTTOM ABOUT BLOCK -->
          <div class="col-md-3 col-sm-6 pre-footer-col">
            <h2>About us</h2>
            <p>Lorem ipsum dolor sit amet, consectetuer adipiscing elit, sed diam sit nonummy nibh euismod tincidunt ut laoreet dolore magna aliquarm erat sit volutpat. Nostrud exerci tation ullamcorper suscipit lobortis nisl aliquip  commodo consequat. </p>
            <p>Duis autem vel eum iriure dolor vulputate velit esse molestie at dolore.</p>
          </div>
          <!-- END BOTTOM ABOUT BLOCK -->
          <!-- BEGIN BOTTOM INFO BLOCK -->
          <div class="col-md-3 col-sm-6 pre-footer-col">
            <h2>Information</h2>
            <ul class="list-unstyled">
              <li><i class="fa fa-angle-right"></i> <a href="javascript:;">Delivery Information</a></li>
              <li><i class="fa fa-angle-right"></i> <a href="javascript:;">Customer Service</a></li>
              <li><i class="fa fa-angle-right"></i> <a href="javascript:;">Order Tracking</a></li>
              <li><i class="fa fa-angle-right"></i> <a href="javascript:;">Shipping &amp; Returns</a></li>
              <li><i class="fa fa-angle-right"></i> <a href="shop-contacts.php">Contact Us</a></li>
              <li><i class="fa fa-angle-right"></i> <a href="javascript:;">Careers</a></li>
              <li><i class="fa fa-angle-right"></i> <a href="javascript:;">Payment Methods</a></li>
            </ul>
          </div>
          <!-- END INFO BLOCK -->

          <!-- BEGIN TWITTER BLOCK --> 
          <div class="col-md-3 col-sm-6 pre-footer-col">
            <h2 class="margin-bottom-0">Latest Tweets</h2>
            <a class="twitter-timeline" href="https://twitter.com/twitterapi" data-tweet-limit="2" data-theme="dark" data-link-color="#57C8EB" data-widget-id="455411516829736961" data-chrome="noheader nofooter noscrollbar noborders transparent">Loading tweets by @keenthemes...</a>      
          </div>
          <!-- END TWITTER BLOCK -->

          <!-- BEGIN BOTTOM CONTACTS -->
          <div class="col-md-3 col-sm-6 pre-footer-col">
            <h2>Our Contacts</h2>
            <address class="margin-bottom-40">
              35, Lorem Lis Street, Park Ave<br>
              California, US<br>
              Phone: 300 323 3456<br>
              Fax: 300 323 1456<br>
              Email: <a href="mailto:info@metronic.com">info@metronic.com</a><br>
              Skype: <a href="skype:metronic">metronic</a>
            </address>
          </div>
          <!-- END BOTTOM CONTACTS -->
        </div>
        <hr>
        <div class="row">
          <!-- BEGIN SOCIAL ICONS -->
          <div class="col-md-6 col-sm-6">
            <ul class="social-icons">
              <li><a class="rss" data-original-title="rss" href="javascript:;"></a></li>
              <li><a class="facebook" data-original-title="facebook" href="javascript:;"></a></li>
              <li><a class="twitter" data-original-title="twitter" href="javascript:;"></a></li>
              <li><a class="googleplus" data-original-title="googleplus" href="javascript:;"></a></li>
              <li><a class="linkedin" data-original-title="linkedin" href="javascript:;"></a></li>
              <li><a class="youtube" data-original-title="youtube" href="javascript:;"></a></li>
              <li><a class="vimeo" data-original-title="vimeo" href="javascript:;"></a></li>
              <li><a class="skype" data-original-title="skype" href="javascript:;"></a></li>
            </ul>
          </div>
          <!-- END SOCIAL ICONS -->
          <!-- BEGIN NEWLETTER -->
          <div class="col-md-6 col-sm-6">
            <div class="pre-footer-subscribe-box pull-right">
              <h2>Newsletter</h2>
              <form action="form-submit.php" method="post">
                <div class="input-group">
                  <input type="text" placeholder="youremail@mail.com" class="form-control" name="youremail_mail_com">
                  <span class="input-group-btn">
                    <button class="btn btn-primary" type="submit">Subscribe</button>
                  </span>
                </div>
              </form>
            </div> 
          </div>
          <!-- END NEWLETTER -->
        </div>
      </div>
    </div>
    <!-- END PRE-FOOTER -->

    <!-- BEGIN FOOTER -->
    <div class="footer">
      <div class="container">
        <div class="row">
          <!-- BEGIN COPYRIGHT -->
          <div class="col-md-4 col-sm-4 padding-top-10">
            2015 © Keenthemes. ALL Rights Reserved. 
          </div>
          <!-- END COPYRIGHT -->
          <!-- BEGIN PAYMENTS -->
          <div class="col-md-4 col-sm-4">
            <ul class="list-unstyled list-inline pull-right">
              <li><img src="assets/corporate/img/payments/western-union.jpg" alt="We accept Western Union" title="We accept Western Union"></li>
              <li><img src="assets/corporate/img/payments/american-express.jpg" alt="We accept American Express" title="We accept American Express"></li>
              <li><img src="assets/corporate/img/payments/MasterCard.jpg" alt="We accept MasterCard" title="We accept MasterCard"></li>
              <li><img src="assets/corporate/img/payments/PayPal.jpg" alt="We accept PayPal" title="We accept PayPal"></li>
              <li><img src="assets/corporate/img/payments/visa.jpg" alt="We accept Visa" title="We accept Visa"></li>
            </ul>
          </div>
          <!-- END PAYMENTS -->
          <!-- BEGIN POWERED -->
          <div class="col-md-4 col-sm-4 text-right">
            <p class="powered">Powered by: <a href="http://www.keenthemes.com/">KeenThemes.com</a></p>
          </div>
          <!-- END POWERED -->
        </div>
      </div>
    </div>
    <!-- END FOOTER -->

    <!-- BEGIN fast view of a product -->
    <div id="product-pop-up" style="display: none; width: 700px;">
            <div class="product-page product-pop-up">
              <div class="row">
                <div class="col-md-6 col-sm-6 col-xs-3">
                  <div class="product-main-image">
                    <img src="assets/pages/img/products/model7.jpg" alt="Cool green dress with red bell" class="img-responsive">
                  </div>
                  <div class="product-other-images">
                    <a href="javascript:;" class="active"><img alt="Berry Lace Dress" src="assets/pages/img/products/model3.jpg"></a>
                    <a href="javascript:;"><img alt="Berry Lace Dress" src="assets/pages/img/products/model4.jpg"></a>
                    <a href="javascript:;"><img alt="Berry Lace Dress" src="assets/pages/img/products/model5.jpg"></a>
                  </div>
                </div>
                <div class="col-md-6 col-sm-6 col-xs-9">
                  <h2>Cool green dress with red bell</h2>
                  <div class="price-availability-block clearfix">
                    <div class="price">
                      <strong><span>$</span>47.00</strong>
                      <em>$<span>62.00</span></em>
                      <strong><span>INR </span>47.00</strong>
                      <em>INR <span>62.00</span></em>
                    </div>
                    <div class="availability">
                      Availability: <strong>In Stock</strong>
                    </div>
                  </div>
                  <div class="description">
                    <p>Lorem ipsum dolor ut sit ame dolore  adipiscing elit, sed nonumy nibh sed euismod laoreet dolore magna aliquarm erat volutpat Nostrud duis molestie at dolore.</p>
                  </div>
                  <div class="product-page-options">
                    <div class="pull-left">
                      <label class="control-label">Size:</label>
                      <select class="form-control input-sm" name="field_0">
                        <option>L</option>
                        <option>M</option>
                        <option>XL</option>
                      </select>
                    </div>
                    <div class="pull-left">
                      <label class="control-label">Color:</label>
                      <select class="form-control input-sm" name="field_0">
                        <option>Red</option>
                        <option>Blue</option>
                        <option>Black</option>
                      </select>
                    </div>
                  </div>
                  <div class="product-page-cart">
                    <div class="product-quantity">
                        <input id="product-quantity" type="text" value="1" readonly name="product-quantity" class="form-control input-sm">
                    </div>
                    <button class="btn btn-primary" type="submit">Add to cart</button>
                    <a href="shop-item.php" class="btn btn-default">More details</a>
                  </div>
                </div>

                <div class="sticker sticker-sale"></div>
              </div>
            </div>
    </div>
    <!-- END fast view of a product -->

    <!-- Load javascripts at bottom, this will reduce page load time -->
    <!-- BEGIN CORE PLUGINS (REQUIRED FOR ALL PAGES) -->
    <!--[if lt IE 9]>
    <script src="assets/plugins/respond.min.js"></script>  
    <![endif]-->
    <script src="assets/plugins/jquery.min.js" type="text/javascript"></script>
    <script src="assets/plugins/jquery-migrate.min.js" type="text/javascript"></script>
    <script src="assets/plugins/bootstrap/js/bootstrap.min.js" type="text/javascript"></script>      
    <script src="assets/corporate/scripts/back-to-top.js" type="text/javascript"></script>
    <script src="assets/plugins/jquery-slimscroll/jquery.slimscroll.min.js" type="text/javascript"></script>
    <!-- END CORE PLUGINS -->

    <!-- BEGIN PAGE LEVEL JAVASCRIPTS (REQUIRED ONLY FOR CURRENT PAGE) -->
    <script src="assets/plugins/fancybox/source/jquery.fancybox.pack.js" type="text/javascript"></script><!-- pop up -->
    <script src="assets/plugins/owl.carousel/owl.carousel.min.js" type="text/javascript"></script><!-- slider for products -->
    <script src='assets/plugins/zoom/jquery.zoom.min.js' type="text/javascript"></script><!-- product zoom -->
    <script src="assets/plugins/bootstrap-touchspin/bootstrap.touchspin.js" type="text/javascript"></script><!-- Quantity -->

    <script src="assets/corporate/scripts/layout.js" type="text/javascript"></script>
    <script src="assets/pages/scripts/bs-carousel.js" type="text/javascript"></script>
    <script src="assets/pages/scripts/shop-modern.js" type="text/javascript"></script>
    <script type="text/javascript">
        jQuery(document).ready(function() {
            Layout.init();    
            Layout.initOWL();
            Layout.initImageZoom();
            Layout.initTouchspin();
            Layout.initTwitter();
        });
    </script>
    <!-- END PAGE LEVEL JAVASCRIPTS -->
</body>
<!-- END BODY -->
</html>


