<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function fe_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ensure_product_reviews_table(mysqli $db): void
{
    $db->query(
        "CREATE TABLE IF NOT EXISTS product_reviews (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            reviewer_name VARCHAR(150) NOT NULL,
            reviewer_email VARCHAR(180) DEFAULT '',
            rating DECIMAL(2,1) NOT NULL DEFAULT 5.0,
            review_text TEXT NOT NULL,
            status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_product_status_created (product_id, status, created_at),
            CONSTRAINT fk_product_reviews_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
}

function normalize_rating(float $rating): float
{
    if ($rating < 1.0) {
        $rating = 1.0;
    } elseif ($rating > 5.0) {
        $rating = 5.0;
    }

    return round($rating * 2) / 2;
}

$db = get_db_connection();
ensure_product_reviews_table($db);
$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$selectedProduct = null;

if ($productId > 0) {
    $stmt = $db->prepare(
        "SELECT p.id, p.name, p.sku, p.image_path, p.description, p.price, p.stock_qty, p.category_id, p.created_at,
                c.name AS category_name, c.slug AS category_slug
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id = ? AND p.is_active = 1
         LIMIT 1"
    );
    if (!$stmt) {
        $stmt = $db->prepare(
            "SELECT p.id, p.name, p.sku, '' AS image_path, p.description, p.price, p.stock_qty, p.category_id, p.created_at,
                    c.name AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id = ? AND p.is_active = 1
             LIMIT 1"
        );
    }
    if ($stmt) {
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $result = $stmt->get_result();
        $selectedProduct = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }
}

if (!$selectedProduct) {
    $fallback = $db->query(
        "SELECT p.id, p.name, p.sku, p.image_path, p.description, p.price, p.stock_qty, p.category_id, p.created_at,
                c.name AS category_name, c.slug AS category_slug
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.is_active = 1
         ORDER BY p.id DESC
         LIMIT 1"
    );
    if ($fallback === false) {
        $fallback = $db->query(
            "SELECT p.id, p.name, p.sku, '' AS image_path, p.description, p.price, p.stock_qty, p.category_id, p.created_at,
                    c.name AS category_name, c.slug AS category_slug
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.is_active = 1
             ORDER BY p.id DESC
             LIMIT 1"
        );
    }
    if ($fallback instanceof mysqli_result) {
        $selectedProduct = $fallback->fetch_assoc();
        $fallback->free();
    }
}

$productName = $selectedProduct ? (string) $selectedProduct['name'] : 'Cool green dress with red bell';
$productPrice = $selectedProduct ? (float) $selectedProduct['price'] : 47.00;
$productStock = $selectedProduct ? (int) $selectedProduct['stock_qty'] : 10;
$productDesc = $selectedProduct ? trim((string) $selectedProduct['description']) : '';
$productSku = $selectedProduct ? trim((string) ($selectedProduct['sku'] ?? '')) : '';
$productCreatedAt = $selectedProduct ? trim((string) ($selectedProduct['created_at'] ?? '')) : '';
$productImg = ($selectedProduct && trim((string) $selectedProduct['image_path']) !== '') ? (string) $selectedProduct['image_path'] : 'assets/pages/img/products/model7.jpg';
$selectedProductId = (int) ($selectedProduct['id'] ?? 0);
$currentCategoryId = (int) ($selectedProduct['category_id'] ?? 0);
$currentCategoryName = trim((string) ($selectedProduct['category_name'] ?? ''));
if ($currentCategoryName === '') {
    $currentCategoryName = 'Uncategorized';
}
$currentCategorySlug = trim((string) ($selectedProduct['category_slug'] ?? ''));

$sidebarCategories = [];
$categoriesResult = $db->query('SELECT id, name, slug FROM categories WHERE is_active = 1 ORDER BY name ASC');
if ($categoriesResult instanceof mysqli_result) {
    while ($categoryRow = $categoriesResult->fetch_assoc()) {
        $sidebarCategories[] = $categoryRow;
    }
    $categoriesResult->free();
}

$categoryFoundInSidebar = false;
foreach ($sidebarCategories as $categoryRow) {
    if ((int) ($categoryRow['id'] ?? 0) === $currentCategoryId) {
        $categoryFoundInSidebar = true;
        break;
    }
}
if ($currentCategoryId > 0 && !$categoryFoundInSidebar) {
    $sidebarCategories[] = [
        'id' => $currentCategoryId,
        'name' => $currentCategoryName,
        'slug' => $currentCategorySlug,
    ];
}

$currentCategoryLink = 'shop-index.php';
if ($currentCategorySlug !== '') {
    $currentCategoryLink = 'shop-index.php?category=' . urlencode($currentCategorySlug);
}

$reviewSuccessMessage = '';
$reviewErrorMessage = '';
$reviewFormName = '';
$reviewFormEmail = '';
$reviewFormText = '';
$reviewFormRating = 4.0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'submit_review') {
    $formProductId = (int) ($_POST['product_id'] ?? 0);
    $reviewFormName = trim((string) ($_POST['name'] ?? ''));
    $reviewFormEmail = trim((string) ($_POST['email'] ?? ''));
    $reviewFormText = trim((string) ($_POST['review'] ?? ''));
    $reviewFormRating = normalize_rating((float) ($_POST['rating'] ?? $_POST['backing5'] ?? 4));

    if ($selectedProductId <= 0 || $formProductId !== $selectedProductId) {
        $reviewErrorMessage = 'Unable to submit review for this product.';
    } elseif ($reviewFormName === '' || $reviewFormText === '') {
        $reviewErrorMessage = 'Name and review are required.';
    } elseif ($reviewFormEmail !== '' && !filter_var($reviewFormEmail, FILTER_VALIDATE_EMAIL)) {
        $reviewErrorMessage = 'Please enter a valid email address.';
    } else {
        $stmt = $db->prepare(
            'INSERT INTO product_reviews (product_id, reviewer_name, reviewer_email, rating, review_text, status)
             VALUES (?, ?, ?, ?, ?, "approved")'
        );
        if ($stmt) {
            $stmt->bind_param('issds', $selectedProductId, $reviewFormName, $reviewFormEmail, $reviewFormRating, $reviewFormText);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                header('Location: shop-item.php?id=' . $selectedProductId . '&review_status=success#Reviews');
                exit;
            }
        }
        $reviewErrorMessage = 'Could not submit your review right now. Please try again.';
    }
}

if ((string) ($_GET['review_status'] ?? '') === 'success') {
    $reviewSuccessMessage = 'Thanks for your review. It is now visible on this product.';
}

$productReviews = [];
$reviewCount = 0;
$reviewAverage = 0.0;
if ($selectedProductId > 0) {
    $statsStmt = $db->prepare(
        "SELECT COUNT(*) AS total_reviews, COALESCE(AVG(rating), 0) AS avg_rating
         FROM product_reviews
         WHERE product_id = ? AND status = 'approved'"
    );
    if ($statsStmt) {
        $statsStmt->bind_param('i', $selectedProductId);
        $statsStmt->execute();
        $statsResult = $statsStmt->get_result();
        $statsRow = $statsResult ? $statsResult->fetch_assoc() : null;
        $statsStmt->close();
        if (is_array($statsRow)) {
            $reviewCount = (int) ($statsRow['total_reviews'] ?? 0);
            $reviewAverage = normalize_rating((float) ($statsRow['avg_rating'] ?? 0));
        }
    }

    $reviewsStmt = $db->prepare(
        "SELECT reviewer_name, reviewer_email, rating, review_text, created_at
         FROM product_reviews
         WHERE product_id = ? AND status = 'approved'
         ORDER BY id DESC"
    );
    if ($reviewsStmt) {
        $reviewsStmt->bind_param('i', $selectedProductId);
        $reviewsStmt->execute();
        $reviewsResult = $reviewsStmt->get_result();
        if ($reviewsResult instanceof mysqli_result) {
            while ($reviewRow = $reviewsResult->fetch_assoc()) {
                $productReviews[] = $reviewRow;
            }
            $reviewsResult->free();
        }
        $reviewsStmt->close();
    }
}

$availabilityLabel = $productStock > 0 ? 'In Stock' : 'Out of Stock';

$showcaseProducts = [];
$showcaseSql = "SELECT p.id, p.name, p.image_path, p.price, p.stock_qty, p.is_featured, p.created_at,
                       c.name AS category_name,
                       COALESCE(rv.review_count, 0) AS review_count,
                       COALESCE(rv.avg_rating, 0) AS avg_rating
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN (
                    SELECT product_id, COUNT(*) AS review_count, AVG(rating) AS avg_rating
                    FROM product_reviews
                    WHERE status = 'approved'
                    GROUP BY product_id
                ) rv ON rv.product_id = p.id
                WHERE p.is_active = 1";
if ($selectedProductId > 0) {
    $showcaseSql .= " AND p.id <> " . $selectedProductId;
}
$showcaseSql .= " ORDER BY rv.review_count DESC, p.is_featured DESC, p.created_at DESC, p.id DESC LIMIT 24";

$showcaseResult = $db->query($showcaseSql);
if ($showcaseResult === false) {
    $showcaseResult = $db->query(
        "SELECT p.id, p.name, p.image_path, p.price, p.stock_qty, p.is_featured, p.created_at,
                c.name AS category_name, 0 AS review_count, 0 AS avg_rating
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.is_active = 1
         ORDER BY p.is_featured DESC, p.created_at DESC, p.id DESC
         LIMIT 24"
    );
}
if ($showcaseResult instanceof mysqli_result) {
    while ($showcaseRow = $showcaseResult->fetch_assoc()) {
        $showcaseProducts[] = $showcaseRow;
    }
    $showcaseResult->free();
}

$bestsellerProducts = array_slice($showcaseProducts, 0, 3);
$popularProducts = array_slice($showcaseProducts, 0, 8);

$relatedProducts = [];
$relatedQuery = "SELECT p.id, p.name, p.image_path, p.price, p.stock_qty, c.name AS category_name
                 FROM products p
                 LEFT JOIN categories c ON c.id = p.category_id
                 WHERE p.is_active = 1";
if ($selectedProductId > 0) {
    $relatedQuery .= " AND p.id <> " . $selectedProductId;
}
$relatedQuery .= " ORDER BY p.id DESC LIMIT 8";
$relatedResult = $db->query($relatedQuery);
if ($relatedResult === false) {
    $relatedResult = $db->query(
        "SELECT p.id, p.name, '' AS image_path, p.price, p.stock_qty, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.is_active = 1
         ORDER BY p.id DESC
         LIMIT 8"
    );
}
if ($relatedResult instanceof mysqli_result) {
    while ($relatedRow = $relatedResult->fetch_assoc()) {
        $relatedProducts[] = $relatedRow;
    }
    $relatedResult->free();
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
  <title><?php echo fe_h($productName); ?> | <?php echo fe_h($currentCategoryName); ?> | Metronic Shop UI</title>

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
  <!-- Fonts END -->

  <!-- Global styles START -->          
  <link href="assets/plugins/font-awesome/css/font-awesome.min.css" rel="stylesheet">
  <link href="assets/plugins/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <!-- Global styles END --> 
   
  <!-- Page level plugin styles START -->
  <link href="assets/plugins/fancybox/source/jquery.fancybox.css" rel="stylesheet">
  <link href="assets/plugins/owl.carousel/assets/owl.carousel.css" rel="stylesheet">
  <link href="assets/plugins/uniform/css/uniform.default.css" rel="stylesheet" type="text/css">
  <link href="http://code.jquery.com/ui/1.10.3/themes/smoothness/jquery-ui.css" rel="stylesheet" type="text/css"><!-- for slider-range -->
  <link href="assets/plugins/rateit/src/rateit.css" rel="stylesheet" type="text/css">
  <!-- Page level plugin styles END -->

  <!-- Theme styles START -->
  <link href="assets/pages/css/components.css" rel="stylesheet">
  <link href="assets/corporate/css/style.css" rel="stylesheet">
  <link href="assets/pages/css/style-shop.css" rel="stylesheet" type="text/css">
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
        <a class="site-logo" href="shop-index.php"><img src="assets/corporate/img/logos/logo-shop-red.png" alt="Metronic Shop UI"></a>

        <a href="javascript:void(0);" class="mobi-toggler"><i class="fa fa-bars"></i></a>

        <!-- BEGIN CART -->
        <div class="top-cart-block">
          <div class="top-cart-info">
            <a href="javascript:void(0);" class="top-cart-info-count">3 items</a>
            <a href="javascript:void(0);" class="top-cart-info-value">$1260</a>
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
            <li class="dropdown active">
              <a class="dropdown-toggle" data-toggle="dropdown" data-target="#" href="javascript:;">
                Pages 
                
              </a>
                
              <ul class="dropdown-menu">
                <li><a href="shop-index.php">Home Default</a></li>
                <li><a href="shop-index-header-fix.php">Home Header Fixed</a></li>
                <li><a href="shop-index-light-footer.php">Home Light Footer</a></li>
                <li><a href="shop-product-list.php">Product List</a></li>
                <li><a href="shop-search-result.php">Search Result</a></li>
                <li class="active"><a href="shop-item.php">Product Page</a></li>
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
    
    <div class="main shop-main-content">
      <div class="container">
        <ul class="breadcrumb">
            <li><a href="shop-index.php">Home</a></li>
            <li><a href="shop-index.php">Store</a></li>
            <li><a href="<?php echo fe_h($currentCategoryLink); ?>"><?php echo fe_h($currentCategoryName); ?></a></li>
            <li class="active"><?php echo fe_h($productName); ?></li>
        </ul>
        <!-- BEGIN SIDEBAR & CONTENT -->
        <div class="row margin-bottom-40">
          <!-- BEGIN SIDEBAR -->
          <div class="sidebar col-md-3 col-sm-5">
            <ul class="list-group margin-bottom-25 sidebar-menu">
              <?php if ($sidebarCategories === []): ?>
                <li class="list-group-item clearfix active">
                  <a href="shop-index.php"><i class="fa fa-angle-right"></i> <?php echo fe_h($currentCategoryName); ?></a>
                </li>
              <?php else: ?>
                <?php foreach ($sidebarCategories as $categoryRow): ?>
                  <?php
                    $categoryId = (int) ($categoryRow['id'] ?? 0);
                    $categoryName = trim((string) ($categoryRow['name'] ?? ''));
                    $categorySlug = trim((string) ($categoryRow['slug'] ?? ''));
                    if ($categoryName === '') {
                        continue;
                    }
                    $categoryLink = 'shop-index.php';
                    if ($categorySlug !== '') {
                        $categoryLink .= '?category=' . urlencode($categorySlug);
                    }
                    $isCurrentCategory = $categoryId > 0 && $categoryId === $currentCategoryId;
                  ?>
                  <li class="list-group-item clearfix<?php echo $isCurrentCategory ? ' active' : ''; ?>">
                    <a href="<?php echo fe_h($categoryLink); ?>"><i class="fa fa-angle-right"></i> <?php echo fe_h($categoryName); ?></a>
                  </li>
                <?php endforeach; ?>
              <?php endif; ?>
            </ul>

            <div class="sidebar-products clearfix">
              <h2>Bestsellers</h2>
              <?php if ($bestsellerProducts === []): ?>
                <p>No bestseller products available right now.</p>
              <?php else: ?>
                <?php foreach ($bestsellerProducts as $best): ?>
                  <?php
                    $bestId = (int) ($best['id'] ?? 0);
                    $bestName = trim((string) ($best['name'] ?? 'Product'));
                    if ($bestName === '') {
                        $bestName = 'Product';
                    }
                    $bestImg = trim((string) ($best['image_path'] ?? ''));
                    if ($bestImg === '') {
                        $bestImg = 'assets/pages/img/products/model1.jpg';
                    }
                    $bestPrice = (float) ($best['price'] ?? 0);
                  ?>
                  <div class="item">
                    <a href="shop-item.php?id=<?php echo $bestId; ?>"><img src="<?php echo fe_h($bestImg); ?>" alt="<?php echo fe_h($bestName); ?>"></a>
                    <h3><a href="shop-item.php?id=<?php echo $bestId; ?>"><?php echo fe_h($bestName); ?></a></h3>
                    <div class="price">$<?php echo number_format($bestPrice, 2); ?></div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
          <!-- END SIDEBAR -->

          <!-- BEGIN CONTENT -->
          <div class="col-md-9 col-sm-7">
            <div class="product-page">
              <div class="row">
                <div class="col-md-6 col-sm-6">
                  <div class="product-main-image">
                    <img src="<?php echo fe_h($productImg); ?>" alt="<?php echo fe_h($productName); ?>" class="img-responsive" data-BigImgsrc="<?php echo fe_h($productImg); ?>" loading="lazy" decoding="async">
                  </div>
                  <div class="product-other-images">
                    <a href="<?php echo fe_h($productImg); ?>" class="fancybox-button active" rel="photos-lib" data-main-image="true"><img alt="<?php echo fe_h($productName); ?>" src="<?php echo fe_h($productImg); ?>" loading="lazy" decoding="async"></a>
                    <a href="assets/pages/img/products/model3.jpg" class="fancybox-button" rel="photos-lib"><img alt="Berry Lace Dress" src="assets/pages/img/products/model3.jpg" loading="lazy" decoding="async"></a>
                    <a href="assets/pages/img/products/model4.jpg" class="fancybox-button" rel="photos-lib"><img alt="Berry Lace Dress" src="assets/pages/img/products/model4.jpg" loading="lazy" decoding="async"></a>
                    <a href="assets/pages/img/products/model5.jpg" class="fancybox-button" rel="photos-lib"><img alt="Berry Lace Dress" src="assets/pages/img/products/model5.jpg" loading="lazy" decoding="async"></a>
                  </div>
                </div>
                <div class="col-md-6 col-sm-6">
                  <h1><?php echo fe_h($productName); ?></h1>
                  <div class="price-availability-block clearfix">
                    <div class="price">
                      <strong><span>$</span><?php echo number_format($productPrice, 2); ?></strong>
                      <em>$<span>62.00</span></em>
                    </div>
                    <div class="availability">
                      Availability: <strong><?php echo $availabilityLabel; ?></strong>
                    </div>
                  </div>
                  <div class="description">
                    <p><?php echo fe_h($productDesc !== '' ? $productDesc : 'No description added yet.'); ?></p>
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
                        <input id="product-quantity" type="text" value="1" readonly class="form-control input-sm" name="product-quantity">
                    </div>
                    <button class="btn btn-primary" type="submit" data-product-id="<?php echo $selectedProductId; ?>">Add to cart</button>
                  </div>
                  <div class="review">
                    <input type="range" value="<?php echo number_format($reviewAverage, 2, '.', ''); ?>" step="0.25" id="backing4" name="backing4">
                    <div class="rateit" data-rateit-backingfld="#backing4" data-rateit-resetable="false" data-rateit-ispreset="true" data-rateit-readonly="true" data-rateit-min="0" data-rateit-max="5">
                    </div>
                    <a href="#Reviews" data-toggle="tab"><?php echo $reviewCount; ?> reviews</a>&nbsp;&nbsp;|&nbsp;&nbsp;<a href="#Reviews" data-toggle="tab">Write a review</a>
                  </div>
                  <ul class="social-icons">
                    <li><a class="facebook" data-original-title="facebook" href="javascript:;"></a></li>
                    <li><a class="twitter" data-original-title="twitter" href="javascript:;"></a></li>
                    <li><a class="googleplus" data-original-title="googleplus" href="javascript:;"></a></li>
                    <li><a class="evernote" data-original-title="evernote" href="javascript:;"></a></li>
                    <li><a class="tumblr" data-original-title="tumblr" href="javascript:;"></a></li>
                  </ul>
                </div>

                <div class="product-page-content">
                  <ul id="myTab" class="nav nav-tabs">
                    <li class="active"><a href="#Description" data-toggle="tab">Description</a></li>
                    <li><a href="#Information" data-toggle="tab">Information</a></li>
                    <li><a href="#Reviews" data-toggle="tab">Reviews (<?php echo $reviewCount; ?>)</a></li>
                  </ul>
                  <div id="myTabContent" class="tab-content">
                    <div class="tab-pane fade in active" id="Description">
                      <p><?php echo nl2br(fe_h($productDesc !== '' ? $productDesc : 'No description added yet.')); ?></p>
                    </div>
                    <div class="tab-pane fade" id="Information">
                      <table class="datasheet">
                        <tr>
                          <th colspan="2">Product details</th>
                        </tr>
                        <tr>
                          <td class="datasheet-features-type">Product ID</td>
                          <td><?php echo $selectedProductId > 0 ? $selectedProductId : '-'; ?></td>
                        </tr>
                        <tr>
                          <td class="datasheet-features-type">SKU</td>
                          <td><?php echo fe_h($productSku !== '' ? $productSku : 'Not set'); ?></td>
                        </tr>
                        <tr>
                          <td class="datasheet-features-type">Category</td>
                          <td><?php echo fe_h($currentCategoryName); ?></td>
                        </tr>
                        <tr>
                          <td class="datasheet-features-type">Price</td>
                          <td>$<?php echo number_format($productPrice, 2); ?></td>
                        </tr>
                        <tr>
                          <td class="datasheet-features-type">Stock</td>
                          <td><?php echo $productStock > 0 ? (int) $productStock . ' available' : 'Out of stock'; ?></td>
                        </tr>
                        <tr>
                          <td class="datasheet-features-type">Availability</td>
                          <td><?php echo $availabilityLabel; ?></td>
                        </tr>
                        <tr>
                          <td class="datasheet-features-type">Created</td>
                          <td><?php echo fe_h($productCreatedAt !== '' ? $productCreatedAt : '-'); ?></td>
                        </tr>
                      </table>
                    </div>
                    <div class="tab-pane fade" id="Reviews">
                      <?php if ($reviewSuccessMessage !== ''): ?>
                        <div class="alert alert-success"><?php echo fe_h($reviewSuccessMessage); ?></div>
                      <?php endif; ?>
                      <?php if ($reviewErrorMessage !== ''): ?>
                        <div class="alert alert-danger"><?php echo fe_h($reviewErrorMessage); ?></div>
                      <?php endif; ?>

                      <?php if ($productReviews === []): ?>
                        <p>There are no reviews for this product yet.</p>
                      <?php else: ?>
                        <?php foreach ($productReviews as $reviewRow): ?>
                          <?php
                            $reviewerName = trim((string) ($reviewRow['reviewer_name'] ?? 'Customer'));
                            if ($reviewerName === '') {
                                $reviewerName = 'Customer';
                            }
                            $reviewDateRaw = trim((string) ($reviewRow['created_at'] ?? ''));
                            $reviewDate = $reviewDateRaw !== '' ? date('d/m/Y - H:i', strtotime($reviewDateRaw)) : '';
                            $reviewRating = normalize_rating((float) ($reviewRow['rating'] ?? 0));
                            $reviewText = trim((string) ($reviewRow['review_text'] ?? ''));
                          ?>
                          <div class="review-item clearfix">
                            <div class="review-item-submitted">
                              <strong><?php echo fe_h($reviewerName); ?></strong>
                              <em><?php echo fe_h($reviewDate); ?></em>
                              <div class="rateit" data-rateit-value="<?php echo number_format($reviewRating, 1, '.', ''); ?>" data-rateit-ispreset="true" data-rateit-readonly="true"></div>
                            </div>
                            <div class="review-item-content">
                              <p><?php echo nl2br(fe_h($reviewText)); ?></p>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      <?php endif; ?>

                      <!-- BEGIN FORM-->
                      <form action="shop-item.php?id=<?php echo $selectedProductId; ?>#Reviews" class="reviews-form" role="form" method="post">
                        <input type="hidden" name="action" value="submit_review">
                        <input type="hidden" name="product_id" value="<?php echo $selectedProductId; ?>">
                        <h2>Write a review</h2>
                        <div class="form-group">
                          <label for="name">Name <span class="require">*</span></label>
                          <input type="text" class="form-control" id="name" name="name" value="<?php echo fe_h($reviewFormName); ?>" required>
                        </div>
                        <div class="form-group">
                          <label for="email">Email</label>
                          <input type="email" class="form-control" id="email" name="email" value="<?php echo fe_h($reviewFormEmail); ?>">
                        </div>
                        <div class="form-group">
                          <label for="review">Review <span class="require">*</span></label>
                          <textarea class="form-control" rows="8" id="review" name="review" required><?php echo fe_h($reviewFormText); ?></textarea>
                        </div>
                        <div class="form-group">
                          <label for="rating">Rating</label>
                          <input type="range" value="<?php echo number_format($reviewFormRating, 2, '.', ''); ?>" step="0.25" id="backing5" name="rating" min="1" max="5">
                          <div class="rateit" data-rateit-backingfld="#backing5" data-rateit-resetable="false"  data-rateit-ispreset="true" data-rateit-min="0" data-rateit-max="5">
                          </div>
                        </div>
                        <div class="padding-top-20">                  
                          <button type="submit" class="btn btn-primary">Send</button>
                        </div>
                      </form>
                      <!-- END FORM--> 
                    </div>
                  </div>
                </div>

                <div class="sticker sticker-sale"></div>
              </div>
            </div>
          </div>
          <!-- END CONTENT -->
        </div>
        <!-- END SIDEBAR & CONTENT -->

        <?php if ($relatedProducts !== []): ?>
          <div class="row margin-bottom-40">
            <div class="col-md-12 col-sm-12">
              <h2>Related products</h2>
              <div class="row product-list">
                <?php foreach (array_slice($relatedProducts, 0, 4) as $related): ?>
                  <?php
                  $relatedImg = trim((string) ($related['image_path'] ?? '')) !== '' ? (string) $related['image_path'] : 'assets/pages/img/products/model1.jpg';
                  $relatedName = (string) ($related['name'] ?? 'Product');
                  $relatedCategoryName = trim((string) ($related['category_name'] ?? ''));
                  if ($relatedCategoryName === '') {
                      $relatedCategoryName = 'Uncategorized';
                  }
                  ?>
                  <div class="col-md-3 col-sm-6 col-xs-12">
                    <div class="product-item"
                      data-product-id="<?php echo (int) $related['id']; ?>"
                      data-product-price="<?php echo number_format((float) $related['price'], 2, '.', ''); ?>"
                      data-stock="<?php echo (int) ($related['stock_qty'] ?? 0); ?>"
                      data-category="<?php echo fe_h($relatedCategoryName); ?>">
                      <div class="pi-img-wrapper">
                        <img src="<?php echo fe_h($relatedImg); ?>" class="img-responsive" alt="<?php echo fe_h($relatedName); ?>" loading="lazy" decoding="async">
                        <div>
                          <a href="<?php echo fe_h($relatedImg); ?>" class="btn btn-default fancybox-button">Zoom</a>
                          <a href="shop-item.php?id=<?php echo (int) $related['id']; ?>" class="btn btn-default js-quick-view">Quick View</a>
                        </div>
                      </div>
                      <h3><a href="shop-item.php?id=<?php echo (int) $related['id']; ?>"><?php echo fe_h($relatedName); ?></a></h3>
                      <div class="pi-price">$<?php echo number_format((float) $related['price'], 2); ?></div>
                      <p class="product-meta"><?php echo fe_h($relatedCategoryName); ?> | <?php echo (int) ($related['stock_qty'] ?? 0) > 0 ? 'In Stock' : 'Out of Stock'; ?></p>
                      <button type="button" class="btn btn-primary js-add-to-cart" data-product-id="<?php echo (int) $related['id']; ?>">Add to cart</button>
                      <a href="shop-item.php?id=<?php echo (int) $related['id']; ?>" class="btn btn-default">Details</a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- BEGIN SIMILAR PRODUCTS -->
        <div class="row margin-bottom-40">
          <div class="col-md-12 col-sm-12">
            <h2>Most popular products</h2>
            <?php if ($popularProducts === []): ?>
              <p>No popular products available right now.</p>
            <?php else: ?>
              <div class="owl-carousel owl-carousel4">
                <?php foreach ($popularProducts as $popular): ?>
                  <?php
                    $popularId = (int) ($popular['id'] ?? 0);
                    $popularName = trim((string) ($popular['name'] ?? 'Product'));
                    if ($popularName === '') {
                        $popularName = 'Product';
                    }
                    $popularImg = trim((string) ($popular['image_path'] ?? ''));
                    if ($popularImg === '') {
                        $popularImg = 'assets/pages/img/products/model2.jpg';
                    }
                    $popularPrice = (float) ($popular['price'] ?? 0);
                    $popularStock = (int) ($popular['stock_qty'] ?? 0);
                    $popularCategory = trim((string) ($popular['category_name'] ?? ''));
                    if ($popularCategory === '') {
                        $popularCategory = 'Uncategorized';
                    }
                    $popularReviewCount = (int) ($popular['review_count'] ?? 0);
                    $popularAvgRating = normalize_rating((float) ($popular['avg_rating'] ?? 0));
                    $isPopularFeatured = (int) ($popular['is_featured'] ?? 0) === 1;
                  ?>
                  <div>
                    <div class="product-item"
                      data-product-id="<?php echo $popularId; ?>"
                      data-product-price="<?php echo number_format($popularPrice, 2, '.', ''); ?>"
                      data-stock="<?php echo $popularStock; ?>"
                      data-category="<?php echo fe_h($popularCategory); ?>">
                      <div class="pi-img-wrapper">
                        <img src="<?php echo fe_h($popularImg); ?>" class="img-responsive" alt="<?php echo fe_h($popularName); ?>">
                        <div>
                          <a href="<?php echo fe_h($popularImg); ?>" class="btn btn-default fancybox-button">Zoom</a>
                          <a href="shop-item.php?id=<?php echo $popularId; ?>" class="btn btn-default js-quick-view">View</a>
                        </div>
                      </div>
                      <h3><a href="shop-item.php?id=<?php echo $popularId; ?>"><?php echo fe_h($popularName); ?></a></h3>
                      <div class="pi-price">$<?php echo number_format($popularPrice, 2); ?></div>
                      <p class="product-meta"><?php echo $popularStock > 0 ? 'In Stock' : 'Out of Stock'; ?> | <?php echo $popularReviewCount; ?> reviews | <?php echo number_format($popularAvgRating, 1); ?>/5</p>
                      <button type="button" class="btn btn-default js-add-to-cart" data-product-id="<?php echo $popularId; ?>">Add to cart</button>
                      <?php if ($isPopularFeatured): ?>
                        <div class="sticker sticker-sale"></div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <!-- END SIMILAR PRODUCTS -->
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
                    </div>
                    <div class="availability">
                      Availability: <strong>In Stock</strong>
                    </div>
                  </div>
                  <div class="description">
                    <p>Lorem ipsum dolor ut sit ame dolore  adipiscing elit, sed nonumy nibh sed euismod laoreet dolore magna aliquarm erat volutpat 
Nostrud duis molestie at dolore.</p>
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
                        <input id="product-quantity2" type="text" value="1" readonly class="form-control input-sm" name="product-quantity2">
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
    <!-- BEGIN CORE PLUGINS(REQUIRED FOR ALL PAGES) -->
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
    <script src="assets/plugins/uniform/jquery.uniform.min.js" type="text/javascript"></script>
    <script src="assets/plugins/rateit/src/jquery.rateit.js" type="text/javascript"></script>

    <script src="assets/corporate/scripts/layout.js" type="text/javascript"></script>
    <script src="assets/pages/scripts/shop-modern.js" type="text/javascript"></script>
    <script type="text/javascript">
        jQuery(document).ready(function() {
            Layout.init();    
            Layout.initOWL();
            Layout.initTwitter();
            Layout.initImageZoom();
            Layout.initTouchspin();
            Layout.initUniform();

            var hash = window.location.hash;
            if (hash && jQuery('#myTab a[href="' + hash + '"]').length) {
                jQuery('#myTab a[href="' + hash + '"]').tab('show');
            }
        });
    </script>
    <!-- END PAGE LEVEL JAVASCRIPTS -->
</body>
<!-- END BODY -->
</html>


