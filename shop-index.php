<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/site-content.php';

function fe_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$db = get_db_connection();
$siteContent = fetch_site_content_map($db);

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

function site_content_int(array $contentMap, string $key, int $fallback, int $min, int $max): int
{
    $raw = trim((string) ($contentMap[$key] ?? ''));
    if ($raw === '' || !is_numeric($raw)) {
        return $fallback;
    }
    $value = (int) $raw;
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

function grid_col_class(int $columns): string
{
    if ($columns <= 1) {
        return 'col-md-12 col-sm-12 col-xs-12';
    }
    if ($columns === 2) {
        return 'col-md-6 col-sm-6 col-xs-12';
    }
    if ($columns === 3) {
        return 'col-md-4 col-sm-6 col-xs-12';
    }
    return 'col-md-3 col-sm-6 col-xs-12';
}

function collect_category_descendant_ids(array $treeByParent, int $parentId): array
{
    $ids = [$parentId];
    $children = $treeByParent[$parentId] ?? [];
    foreach ($children as $child) {
        $childId = (int) ($child['id'] ?? 0);
        if ($childId > 0) {
            $ids = array_merge($ids, collect_category_descendant_ids($treeByParent, $childId));
        }
    }
    return $ids;
}

function render_index_category_sidebar(array $treeByParent, int $parentId, string $selectedSlug): void
{
    $children = $treeByParent[$parentId] ?? [];
    if ($children === []) {
        return;
    }

    foreach ($children as $node) {
        $slug = trim((string) ($node['slug'] ?? ''));
        $name = trim((string) ($node['name'] ?? 'Category'));
        if ($slug === '' || $name === '') {
            continue;
        }
        $isActive = $selectedSlug === $slug;
        echo '<li class="list-group-item clearfix' . ($isActive ? ' active' : '') . '">';
        echo '<a href="shop-index.php?category=' . rawurlencode($slug) . '#featured-products"><i class="fa fa-angle-right"></i> ' . fe_h($name) . '</a>';
        if (!empty($treeByParent[(int) ($node['id'] ?? 0)])) {
            echo '<ul class="dropdown-menu" style="display:block;">';
            render_index_category_sidebar($treeByParent, (int) $node['id'], $selectedSlug);
            echo '</ul>';
        }
        echo '</li>';
    }
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
$categoriesBySlug = [];
$categoryTree = [];
$categoriesResult = $db->query('SELECT id, name, slug, parent_id, is_active FROM categories WHERE is_active = 1 ORDER BY name ASC');
if ($categoriesResult instanceof mysqli_result) {
    while ($row = $categoriesResult->fetch_assoc()) {
        $categories[] = $row;
        $slugKey = trim((string) ($row['slug'] ?? ''));
        if ($slugKey !== '') {
            $categoriesBySlug[$slugKey] = (int) ($row['id'] ?? 0);
        }
        $parentId = (int) ($row['parent_id'] ?? 0);
        $categoryTree[$parentId][] = $row;
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
if ($selectedCategorySlug !== '' && $selectedCategoryId <= 0 && isset($categoriesBySlug[$selectedCategorySlug])) {
    $selectedCategoryId = (int) $categoriesBySlug[$selectedCategorySlug];
}
if ($selectedCategoryId > 0) {
    $selectedCategoryIds = array_values(array_unique(array_map('intval', collect_category_descendant_ids($categoryTree, $selectedCategoryId))));
    $products = array_values(array_filter($allProducts, static function (array $product) use ($selectedCategoryIds): bool {
        return in_array((int) ($product['category_id'] ?? 0), $selectedCategoryIds, true);
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
    <?php require_once __DIR__ . '/includes/shop-header.php'; ?>

    <?php if (!empty($sectionConfig['hero']['enabled'])): ?>
    <?php require_once __DIR__ . '/includes/shop-hero.php'; ?>
    <?php endif; ?>

    <div class="main shop-main-content">
      <div class="container">
        <?php
          $hasRenderedMainSection = false;
          $homeRendered = false;
          $categoriesEnabled = (bool) ($sectionConfig['categories_sidebar']['enabled'] ?? true);
          $productsSectionTitle = site_content_value($siteContent, 'home_products_heading', (string) ($sectionConfig['products_from_admin']['title'] ?? 'Products From Admin'));
          $newArrivalsTitle = site_content_value($siteContent, 'new_arrivals_heading', (string) ($sectionConfig['new_arrivals']['title'] ?? 'New Arrivals'));
          $featuredTitle = site_content_value($siteContent, 'featured_heading', (string) ($sectionConfig['featured']['title'] ?? 'Featured'));
          $allCategoriesLabel = site_content_value($siteContent, 'sidebar_all_categories_label', 'All Categories');
          $filterTitle = site_content_value($siteContent, 'sidebar_filter_title', 'Filter');
          $homeProductsLimit = site_content_int($siteContent, 'home_products_limit', 12, 1, 48);
          $newArrivalsLimit = site_content_int($siteContent, 'new_arrivals_limit', 8, 1, 48);
          $featuredLimit = site_content_int($siteContent, 'featured_limit', 8, 1, 48);
          $homeColumns = site_content_int($siteContent, 'home_products_columns', 3, 1, 4);
          $newArrivalsColumns = site_content_int($siteContent, 'new_arrivals_columns', 4, 1, 4);
          $featuredColumns = site_content_int($siteContent, 'featured_columns', 4, 1, 4);
          $homeColClass = grid_col_class($homeColumns);
          $newArrivalsColClass = grid_col_class($newArrivalsColumns);
          $featuredColClass = grid_col_class($featuredColumns);
          $homeProductsForView = array_slice($homeProducts, 0, $homeProductsLimit);
          $newArrivalProductsForView = array_slice($newArrivalProducts, 0, $newArrivalsLimit);
          $featuredProductsForView = array_slice($featuredProducts, 0, $featuredLimit);
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
                      <a href="shop-index.php#featured-products"><i class="fa fa-angle-right"></i> <?php echo fe_h($allCategoriesLabel); ?></a>
                    </li>
                    <?php render_index_category_sidebar($categoryTree, 0, $selectedCategorySlug); ?>
                  </ul>
                  <div
                    class="sidebar-filter"
                    data-filter-title="<?php echo fe_h($filterTitle); ?>"
                    data-filter-all-categories-label="<?php echo fe_h($allCategoriesLabel); ?>"
                  ></div>
                </div>
              <?php endif; ?>

              <div class="<?php echo $categoriesEnabled ? 'col-md-9 col-sm-8' : 'col-md-12'; ?> sale-product">
                <h2><?php echo fe_h($productsSectionTitle); ?></h2>
                <?php if ($homeProductsForView !== []): ?>
                  <div class="row product-list js-product-grid js-catalog-grid">
                    <?php foreach ($homeProductsForView as $product): ?>
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
                      <div class="<?php echo fe_h($homeColClass); ?>">
                        <div class="product-item"
                          data-product-id="<?php echo $productId; ?>"
                          data-product-price="<?php echo number_format((float) ($product['price'] ?? 0), 2, '.', ''); ?>"
                          data-stock="<?php echo (int) ($product['stock_qty'] ?? 0); ?>"
                          data-category="<?php echo fe_h($categoryName); ?>">
                          <div class="pi-img-wrapper">
                            <a href="shop-item.php?id=<?php echo $productId; ?>" class="product-image-link">
                              <img src="<?php echo fe_h($img); ?>" class="img-responsive" alt="<?php echo fe_h((string) ($product['name'] ?? 'Product')); ?>" loading="lazy" decoding="async">
                            </a>
                            <div>
                              <a href="shop-item.php?id=<?php echo $productId; ?>" class="btn btn-default js-quick-view">Quick View</a>
                            </div>
                          </div>
                          <h3><a href="shop-item.php?id=<?php echo $productId; ?>"><?php echo fe_h((string) ($product['name'] ?? 'Product')); ?></a></h3>
                          <div class="pi-price">₹ <?php echo number_format((float) ($product['price'] ?? 0), 2); ?></div>
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
                <?php if ($newArrivalProductsForView !== []): ?>
                  <div class="row product-list">
                    <?php foreach ($newArrivalProductsForView as $product): ?>
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
                      <div class="<?php echo fe_h($newArrivalsColClass); ?>">
                        <div class="product-item"
                          data-product-id="<?php echo $productId; ?>"
                          data-product-price="<?php echo number_format((float) ($product['price'] ?? 0), 2, '.', ''); ?>"
                          data-stock="<?php echo (int) ($product['stock_qty'] ?? 0); ?>"
                          data-category="<?php echo fe_h($categoryName); ?>">
                          <div class="pi-img-wrapper">
                            <a href="shop-item.php?id=<?php echo $productId; ?>" class="product-image-link">
                              <img src="<?php echo fe_h($img); ?>" class="img-responsive" alt="<?php echo fe_h((string) ($product['name'] ?? 'Product')); ?>" loading="lazy" decoding="async">
                            </a>
                            <div>
                              <a href="shop-item.php?id=<?php echo $productId; ?>" class="btn btn-default js-quick-view">Quick View</a>
                            </div>
                          </div>
                          <h3><a href="shop-item.php?id=<?php echo $productId; ?>"><?php echo fe_h((string) ($product['name'] ?? 'Product')); ?></a></h3>
                          <div class="pi-price">₹ <?php echo number_format((float) ($product['price'] ?? 0), 2); ?></div>
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
                <?php if ($featuredProductsForView !== []): ?>
                  <div class="row product-list">
                    <?php foreach ($featuredProductsForView as $product): ?>
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
                      <div class="<?php echo fe_h($featuredColClass); ?>">
                        <div class="product-item"
                          data-product-id="<?php echo $productId; ?>"
                          data-product-price="<?php echo number_format((float) ($product['price'] ?? 0), 2, '.', ''); ?>"
                          data-stock="<?php echo (int) ($product['stock_qty'] ?? 0); ?>"
                          data-category="<?php echo fe_h($categoryName); ?>">
                          <div class="pi-img-wrapper">
                            <a href="shop-item.php?id=<?php echo $productId; ?>" class="product-image-link">
                              <img src="<?php echo fe_h($img); ?>" class="img-responsive" alt="<?php echo fe_h((string) ($product['name'] ?? 'Product')); ?>" loading="lazy" decoding="async">
                            </a>
                            <div>
                              <a href="shop-item.php?id=<?php echo $productId; ?>" class="btn btn-default js-quick-view">Quick View</a>
                            </div>
                          </div>
                          <h3><a href="shop-item.php?id=<?php echo $productId; ?>"><?php echo fe_h((string) ($product['name'] ?? 'Product')); ?></a></h3>
                          <div class="pi-price">₹ <?php echo number_format((float) ($product['price'] ?? 0), 2); ?></div>
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
                      <strong><span>₹ </span>47.00</strong>
                      <em>₹ <span>62.00</span></em>
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


