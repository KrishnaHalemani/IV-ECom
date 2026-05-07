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

function parse_option_list(string $raw): array
{
    $parts = preg_split('/[,\\n\\r]+/', $raw) ?: [];
    $clean = [];
    foreach ($parts as $part) {
        $value = trim((string) $part);
        if ($value === '') {
            continue;
        }
        $clean[mb_strtolower($value)] = $value;
    }
    return array_values($clean);
}

function parse_gallery_images(string $json): array
{
    $json = trim($json);
    if ($json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $images = [];
    foreach ($decoded as $path) {
        $imagePath = trim((string) $path);
        if ($imagePath !== '') {
            $images[$imagePath] = true;
        }
    }
    return array_keys($images);
}

function ensure_product_option_columns(mysqli $db): void
{
    $checks = [
        'size_options' => "ALTER TABLE products ADD COLUMN size_options TEXT NULL AFTER display_section",
        'color_options' => "ALTER TABLE products ADD COLUMN color_options TEXT NULL AFTER size_options",
        'gallery_images_json' => "ALTER TABLE products ADD COLUMN gallery_images_json LONGTEXT NULL AFTER color_options",
        'color_image_map_json' => "ALTER TABLE products ADD COLUMN color_image_map_json LONGTEXT NULL AFTER gallery_images_json",
    ];

    foreach ($checks as $column => $sql) {
        $result = $db->query("SHOW COLUMNS FROM products LIKE '{$column}'");
        $missing = $result instanceof mysqli_result ? $result->num_rows === 0 : true;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        if ($missing) {
            $db->query($sql);
        }
    }
}

function parse_color_image_map(string $json): array
{
    $json = trim($json);
    if ($json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $map = [];
    foreach ($decoded as $color => $paths) {
        $colorKey = mb_strtoupper(trim((string) $color));
        if ($colorKey === '' || !is_array($paths)) {
            continue;
        }
        $cleanPaths = [];
        foreach ($paths as $path) {
            $p = trim((string) $path);
            if ($p !== '') {
                $cleanPaths[$p] = true;
            }
        }
        if ($cleanPaths !== []) {
            $map[$colorKey] = array_keys($cleanPaths);
        }
    }
    return $map;
}

$db = get_db_connection();
ensure_product_reviews_table($db);
ensure_product_option_columns($db);
$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$selectedProduct = null;

if ($productId > 0) {
    $stmt = $db->prepare(
        "SELECT p.id, p.name, p.sku, p.image_path, p.description, p.price, p.stock_qty, p.size_options, p.color_options, p.gallery_images_json, p.color_image_map_json, p.category_id, p.created_at,
                c.name AS category_name, c.slug AS category_slug
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id = ? AND p.is_active = 1
         LIMIT 1"
    );
    if (!$stmt) {
        $stmt = $db->prepare(
            "SELECT p.id, p.name, p.sku, '' AS image_path, p.description, p.price, p.stock_qty, p.size_options, p.color_options, p.gallery_images_json, p.color_image_map_json, p.category_id, p.created_at,
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
        "SELECT p.id, p.name, p.sku, p.image_path, p.description, p.price, p.stock_qty, p.size_options, p.color_options, p.gallery_images_json, p.color_image_map_json, p.category_id, p.created_at,
                c.name AS category_name, c.slug AS category_slug
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.is_active = 1
         ORDER BY p.id DESC
         LIMIT 1"
    );
    if ($fallback === false) {
        $fallback = $db->query(
            "SELECT p.id, p.name, p.sku, '' AS image_path, p.description, p.price, p.stock_qty, p.size_options, p.color_options, p.gallery_images_json, p.color_image_map_json, p.category_id, p.created_at,
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
$sizeOptions = parse_option_list((string) ($selectedProduct['size_options'] ?? ''));
$colorOptions = parse_option_list((string) ($selectedProduct['color_options'] ?? ''));
$galleryImages = parse_gallery_images((string) ($selectedProduct['gallery_images_json'] ?? ''));
$colorImageMap = parse_color_image_map((string) ($selectedProduct['color_image_map_json'] ?? ''));
$productImageSet = [$productImg];
foreach ($galleryImages as $galleryImage) {
    if (!in_array($galleryImage, $productImageSet, true)) {
        $productImageSet[] = $galleryImage;
    }
}
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
    <?php require_once __DIR__ . '/includes/shop-header.php'; ?>
    
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
                    <div class="price">&#8377; <?php echo number_format($bestPrice, 2); ?></div>
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
                    <?php foreach ($productImageSet as $index => $imagePath): ?>
                      <?php
                      $thumbColors = [];
                      foreach ($colorImageMap as $colorKey => $colorPaths) {
                          if (in_array($imagePath, $colorPaths, true)) {
                              $thumbColors[] = $colorKey;
                          }
                      }
                      $thumbColorAttr = implode(',', $thumbColors);
                      ?>
                      <a href="<?php echo fe_h($imagePath); ?>" class="fancybox-button<?php echo $index === 0 ? ' active' : ''; ?>" rel="photos-lib" data-main-image="true">
                        <img alt="<?php echo fe_h($productName); ?>" src="<?php echo fe_h($imagePath); ?>" loading="lazy" decoding="async" data-colors="<?php echo fe_h($thumbColorAttr); ?>">
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="col-md-6 col-sm-6">
                  <h1><?php echo fe_h($productName); ?></h1>
                  <div class="price-availability-block clearfix">
                    <div class="price">
                      <strong><span>&#8377;</span><?php echo number_format($productPrice, 2); ?></strong>
                      <em>&#8377;<span>62.00</span></em>
                    </div>
                    <div class="availability">
                      Availability: <strong><?php echo $availabilityLabel; ?></strong>
                    </div>
                  </div>
                  <div class="description">
                    <p><?php echo fe_h($productDesc !== '' ? $productDesc : 'No description added yet.'); ?></p>
                  </div>
                  <div class="product-page-options">
                    <?php if ($sizeOptions !== []): ?>
                      <div class="pull-left">
                        <label class="control-label">Size:</label>
                        <select class="form-control input-sm" name="product_size">
                          <?php foreach ($sizeOptions as $sizeValue): ?>
                            <option><?php echo fe_h($sizeValue); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    <?php endif; ?>
                    <?php if ($colorOptions !== []): ?>
                      <div class="pull-left">
                        <label class="control-label">Color:</label>
                        <select class="form-control input-sm" name="product_color" id="product-color-select">
                          <?php foreach ($colorOptions as $colorValue): ?>
                            <option><?php echo fe_h($colorValue); ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="product-page-cart">
                    <div class="product-quantity">
                        <button type="button" class="btn btn-default btn-sm" id="qty-decrease">-</button>
                        <input id="product-quantity" type="text" value="1" readonly class="form-control input-sm" name="product-quantity" style="display:inline-block; width:60px; text-align:center;">
                        <button type="button" class="btn btn-default btn-sm" id="qty-increase">+</button>
                    </div>
                    <button class="btn btn-primary" type="submit" data-product-id="<?php echo $selectedProductId; ?>">Add to cart</button>
                  </div>
                  <div class="review">
                    <input type="range" value="<?php echo number_format($reviewAverage, 2, '.', ''); ?>" step="0.25" id="backing4" name="backing4">
                    <div class="rateit" data-rateit-backingfld="#backing4" data-rateit-resetable="false" data-rateit-ispreset="true" data-rateit-readonly="true" data-rateit-min="0" data-rateit-max="5">
                    </div>
                    <a href="#Reviews" data-toggle="tab"><?php echo $reviewCount; ?> reviews</a>&nbsp;&nbsp;|&nbsp;&nbsp;<a href="#Reviews" data-toggle="tab">Write a review</a>
                  </div>
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
                          <td>&#8377; <?php echo number_format($productPrice, 2); ?></td>
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
                      <div class="pi-price">&#8377; <?php echo number_format((float) $related['price'], 2); ?></div>
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
                      <div class="pi-price">&#8377; <?php echo number_format($popularPrice, 2); ?></div>
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

    
<?php require_once __DIR__ . '/includes/shop-footer.php'; ?>

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
                      <strong><span>&#8377;</span>47.00</strong>
                      <em>&#8377;<span>62.00</span></em>
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

            jQuery('.product-other-images a[data-main-image="true"]').on('click', function () {
                var src = jQuery(this).attr('href');
                if (!src) {
                    return;
                }
                var mainImage = jQuery('.product-main-image img');
                mainImage.attr('src', src);
                mainImage.attr('data-BigImgsrc', src);
                jQuery('.product-other-images a').removeClass('active');
                jQuery(this).addClass('active');
            });

            var colorSelect = jQuery('#product-color-select');
            function applyColorImages() {
                if (!colorSelect.length) {
                    return;
                }
                var selectedColor = String(colorSelect.val() || '').trim().toUpperCase();
                var thumbs = jQuery('.product-other-images a[data-main-image="true"]');
                if (!thumbs.length || selectedColor === '') {
                    return;
                }

                var firstMatch = null;
                thumbs.each(function () {
                    var img = jQuery(this).find('img');
                    var colors = String(img.data('colors') || '').toUpperCase();
                    var hasColor = colors !== '' && colors.split(',').indexOf(selectedColor) !== -1;
                    if (colors === '') {
                        jQuery(this).show();
                        if (!firstMatch) {
                            firstMatch = jQuery(this);
                        }
                    } else if (hasColor) {
                        jQuery(this).show();
                        if (!firstMatch) {
                            firstMatch = jQuery(this);
                        }
                    } else {
                        jQuery(this).hide();
                    }
                });

                if (firstMatch && firstMatch.length) {
                    firstMatch.trigger('click');
                } else {
                    thumbs.show();
                }
            }

            colorSelect.on('change', applyColorImages);
            applyColorImages();

            var qtyInput = jQuery('#product-quantity');
            jQuery('#qty-increase').on('click', function () {
                var current = parseInt(qtyInput.val(), 10);
                if (isNaN(current) || current < 1) {
                    current = 1;
                }
                qtyInput.val(current + 1);
            });
            jQuery('#qty-decrease').on('click', function () {
                var current = parseInt(qtyInput.val(), 10);
                if (isNaN(current) || current <= 1) {
                    qtyInput.val(1);
                    return;
                }
                qtyInput.val(current - 1);
            });
        });
    </script>
    <!-- END PAGE LEVEL JAVASCRIPTS -->
</body>
<!-- END BODY -->
</html>


