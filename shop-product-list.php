<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function fe_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$db = get_db_connection();
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
$selectedCategory = trim((string) ($_GET['category'] ?? ''));
$selectedCategoryName = 'All Products';
$catalogProducts = [];
$categoryTree = [];
$categoriesById = [];
$categoriesBySlug = [];
$selectedCategoryId = 0;
$selectedCategorySlug = '';
$selectedCategoryIds = [];
$sort = trim((string) ($_GET['sort'] ?? 'latest'));
$show = (int) ($_GET['show'] ?? 24);
$page = (int) ($_GET['page'] ?? 1);
if (!in_array($show, [12, 24, 48, 96], true)) {
    $show = 24;
}
if ($page < 1) {
    $page = 1;
}

function category_slug_or_id(array $category): string
{
    $slug = trim((string) ($category['slug'] ?? ''));
    if ($slug !== '') {
        return $slug;
    }
    return (string) ((int) ($category['id'] ?? 0));
}

function collect_child_ids(array $treeByParent, int $parentId): array
{
    $ids = [$parentId];
    $children = $treeByParent[$parentId] ?? [];
    foreach ($children as $child) {
        $childId = (int) ($child['id'] ?? 0);
        if ($childId > 0) {
            $ids = array_merge($ids, collect_child_ids($treeByParent, $childId));
        }
    }
    return $ids;
}

function render_category_sidebar(array $treeByParent, int $parentId, int $selectedId): void
{
    $children = $treeByParent[$parentId] ?? [];
    if ($children === []) {
        return;
    }
    foreach ($children as $node) {
        $id = (int) ($node['id'] ?? 0);
        $name = trim((string) ($node['name'] ?? 'Category'));
        $isActive = $id === $selectedId;
        echo '<li class="list-group-item clearfix' . ($isActive ? ' active' : '') . '">';
        echo '<a href="shop-product-list.php?category=' . rawurlencode(category_slug_or_id($node)) . '"><i class="fa fa-angle-right"></i> ' . fe_h($name) . '</a>';
        if (!empty($treeByParent[$id])) {
            echo '<ul class="dropdown-menu" style="display:block;">';
            render_category_sidebar($treeByParent, $id, $selectedId);
            echo '</ul>';
        }
        echo '</li>';
    }
}

$categoryResult = $db->query(
    "SELECT id, name, slug, parent_id
     FROM categories
     WHERE is_active = 1
     ORDER BY COALESCE(parent_id, 0) ASC, name ASC"
);
if ($categoryResult instanceof mysqli_result) {
    while ($row = $categoryResult->fetch_assoc()) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $categoriesById[$id] = $row;
        $slugKey = strtolower(trim((string) ($row['slug'] ?? '')));
        if ($slugKey !== '') {
            $categoriesBySlug[$slugKey] = $id;
        }
        $parentId = (int) ($row['parent_id'] ?? 0);
        $categoryTree[$parentId][] = $row;
    }
    $categoryResult->free();
}

if ($selectedCategory !== '') {
    if (ctype_digit($selectedCategory)) {
        $selectedCategoryId = (int) $selectedCategory;
    } else {
        $lookup = strtolower($selectedCategory);
        $selectedCategoryId = (int) ($categoriesBySlug[$lookup] ?? 0);
    }
}
if ($selectedCategoryId > 0 && isset($categoriesById[$selectedCategoryId])) {
    $selectedCategoryName = (string) ($categoriesById[$selectedCategoryId]['name'] ?? 'All Products');
    $selectedCategorySlug = category_slug_or_id($categoriesById[$selectedCategoryId]);
    $selectedCategoryIds = array_values(array_unique(array_map('intval', collect_child_ids($categoryTree, $selectedCategoryId))));
}

$hasImageField = true;
$testImageSql = $db->query("SHOW COLUMNS FROM products LIKE 'image_path'");
if (!$testImageSql instanceof mysqli_result || $testImageSql->num_rows === 0) {
    $hasImageField = false;
}
if ($testImageSql instanceof mysqli_result) {
    $testImageSql->free();
}

$imageField = $hasImageField ? 'p.image_path' : "'' AS image_path";
$whereSql = 'p.is_active = 1';
if ($selectedCategoryIds !== []) {
    $whereSql .= ' AND p.category_id IN (' . implode(',', array_map('intval', $selectedCategoryIds)) . ')';
}

$orderSql = 'p.id DESC';
if ($sort === 'name_asc') {
    $orderSql = 'p.name ASC';
} elseif ($sort === 'name_desc') {
    $orderSql = 'p.name DESC';
} elseif ($sort === 'price_asc') {
    $orderSql = 'p.price ASC';
} elseif ($sort === 'price_desc') {
    $orderSql = 'p.price DESC';
}

$countSql = "SELECT COUNT(*) AS total_rows
             FROM products p
             WHERE {$whereSql}";
$totalRows = 0;
$countResult = $db->query($countSql);
if ($countResult instanceof mysqli_result) {
    $countRow = $countResult->fetch_assoc();
    $totalRows = (int) ($countRow['total_rows'] ?? 0);
    $countResult->free();
}
$totalPages = max(1, (int) ceil($totalRows / $show));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = max(0, ($page - 1) * $show);

$sql = "SELECT p.id, p.name, {$imageField}, p.price, p.stock_qty, c.name AS category_name, c.slug AS category_slug
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE {$whereSql}
        ORDER BY {$orderSql}
        LIMIT {$show} OFFSET {$offset}";

$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->execute();
    $catalogResult = $stmt->get_result();
    if ($catalogResult instanceof mysqli_result) {
        while ($row = $catalogResult->fetch_assoc()) {
            $catalogProducts[] = $row;
        }
        $catalogResult->free();
    }
    $stmt->close();
}

$bestsellers = [];
$bestsellerWhere = 'p.is_active = 1';
if ($selectedCategoryIds !== []) {
    $bestsellerWhere .= ' AND p.category_id IN (' . implode(',', array_map('intval', $selectedCategoryIds)) . ')';
}
$bestsellerSql = "SELECT p.id, p.name, {$imageField}, p.price
                  FROM products p
                  WHERE {$bestsellerWhere}
                  ORDER BY p.id DESC
                  LIMIT 3";
$bestsellerResult = $db->query($bestsellerSql);
if ($bestsellerResult instanceof mysqli_result) {
    while ($row = $bestsellerResult->fetch_assoc()) {
        $bestsellers[] = $row;
    }
    $bestsellerResult->free();
}

$pageBaseParams = ['sort' => $sort, 'show' => $show];
if ($selectedCategorySlug !== '') {
    $pageBaseParams['category'] = $selectedCategorySlug;
}
$firstItem = $totalRows > 0 ? ($offset + 1) : 0;
$lastItem = min($totalRows, $offset + $show);
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
  <title><?php echo fe_h($selectedCategoryName); ?> | Metronic Shop UI</title>

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
  <link href="assets/pages/css/animate.css" rel="stylesheet">
  <link href="assets/plugins/fancybox/source/jquery.fancybox.css" rel="stylesheet">
  <link href="assets/plugins/owl.carousel/assets/owl.carousel.css" rel="stylesheet">
  <link href="assets/plugins/uniform/css/uniform.default.css" rel="stylesheet" type="text/css">
  <link href="http://code.jquery.com/ui/1.10.3/themes/smoothness/jquery-ui.css" rel="stylesheet" type="text/css"><!-- for slider-range -->
  <link href="assets/plugins/rateit/src/rateit.css" rel="stylesheet" type="text/css">
  <!-- Page level plugin styles END -->

  <!-- Theme styles START -->
  <link href="assets/pages/css/components.css" rel="stylesheet">
  <link href="assets/pages/css/slider.css" rel="stylesheet">
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
    <?php require_once __DIR__ . '/includes/shop-hero.php'; ?>

    <div class="main shop-main-content">
      <div class="container">
        <ul class="breadcrumb">
            <li><a href="shop-index.php">Home</a></li>
            <li><a href="shop-index.php">Store</a></li>
            <li class="active"><?php echo fe_h($selectedCategoryName); ?></li>
        </ul>
        <!-- BEGIN SIDEBAR & CONTENT -->
        <div class="row margin-bottom-40">
          <!-- BEGIN SIDEBAR -->
          <div class="sidebar col-md-3 col-sm-5">
            <ul class="list-group margin-bottom-25 sidebar-menu">
              <li class="list-group-item clearfix <?php echo $selectedCategoryId === 0 ? 'active' : ''; ?>">
                <a href="shop-product-list.php"><i class="fa fa-angle-right"></i> All Products</a>
              </li>
              <?php render_category_sidebar($categoryTree, 0, $selectedCategoryId); ?>
            </ul>

            <div class="margin-bottom-25">
              <div
                class="sidebar-filter"
                data-filter-title="Filter"
                data-filter-all-categories-label="All Categories"
              ></div>
            </div>

            <div class="sidebar-products clearfix">
              <h2>Bestsellers</h2>
              <?php if ($bestsellers === []): ?>
                <p>No bestselling products available right now.</p>
              <?php else: ?>
                <?php foreach ($bestsellers as $best): ?>
                  <?php $bestImg = trim((string) ($best['image_path'] ?? '')) !== '' ? (string) $best['image_path'] : 'assets/pages/img/products/model1.jpg'; ?>
                  <div class="item">
                    <a href="shop-item.php?id=<?php echo (int) ($best['id'] ?? 0); ?>"><img src="<?php echo fe_h($bestImg); ?>" alt="<?php echo fe_h((string) ($best['name'] ?? 'Product')); ?>"></a>
                    <h3><a href="shop-item.php?id=<?php echo (int) ($best['id'] ?? 0); ?>"><?php echo fe_h((string) ($best['name'] ?? 'Product')); ?></a></h3>
                    <div class="price">₹ <?php echo number_format((float) ($best['price'] ?? 0), 2); ?></div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
          <!-- END SIDEBAR -->
          <!-- BEGIN CONTENT -->
          <div class="col-md-9 col-sm-7">
            <div class="row list-view-sorting clearfix">
              <div class="col-md-2 col-sm-2 list-view">
                <a href="javascript:;"><i class="fa fa-th-large"></i></a>
                <a href="javascript:;"><i class="fa fa-th-list"></i></a>
              </div>
              <div class="col-md-10 col-sm-10">
                <div class="pull-right">
                  <label class="control-label">Show:</label>
                  <select class="form-control input-sm" id="catalog-show">
                    <?php foreach ([12, 24, 48, 96] as $showOption): ?>
                      <option value="<?php echo $showOption; ?>" <?php echo $show === $showOption ? 'selected' : ''; ?>><?php echo $showOption; ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="pull-right">
                  <label class="control-label">Sort&nbsp;By:</label>
                  <select class="form-control input-sm" id="catalog-sort">
                    <option value="latest" <?php echo $sort === 'latest' ? 'selected' : ''; ?>>Default</option>
                    <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name (A - Z)</option>
                    <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name (Z - A)</option>
                    <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price (Low &gt; High)</option>
                    <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price (High &gt; Low)</option>
                  </select>
                </div>
              </div>
            </div>
            <?php if ($catalogProducts !== []): ?>
              <div class="row product-list js-catalog-grid">
                <?php foreach ($catalogProducts as $product): ?>
                  <?php $img = trim((string) ($product['image_path'] ?? '')) !== '' ? (string) $product['image_path'] : 'assets/pages/img/products/model1.jpg'; ?>
                  <?php $categoryName = trim((string) ($product['category_name'] ?? '')) !== '' ? (string) $product['category_name'] : 'General'; ?>
                  <div class="col-md-3 col-sm-6 col-xs-12">
                    <div class="product-item"
                      data-product-id="<?php echo (int) $product['id']; ?>"
                      data-product-price="<?php echo number_format((float) $product['price'], 2, '.', ''); ?>"
                      data-stock="<?php echo (int) $product['stock_qty']; ?>"
                      data-category="<?php echo fe_h($categoryName); ?>">
                      <div class="pi-img-wrapper">
                        <img src="<?php echo fe_h($img); ?>" class="img-responsive" alt="<?php echo fe_h((string) $product['name']); ?>" loading="lazy" decoding="async">
                        <div>
                          <a href="<?php echo fe_h($img); ?>" class="btn btn-default fancybox-button">Zoom</a>
                          <a href="shop-item.php?id=<?php echo (int) $product['id']; ?>" class="btn btn-default js-quick-view">Quick View</a>
                        </div>
                      </div>
                      <h3><a href="shop-item.php?id=<?php echo (int) $product['id']; ?>"><?php echo fe_h((string) $product['name']); ?></a></h3>
                      <div class="pi-price">₹ <?php echo number_format((float) $product['price'], 2); ?></div>
                      <p class="product-meta"><?php echo fe_h($categoryName); ?> | <?php echo (int) $product['stock_qty'] > 0 ? 'In Stock' : 'Out of Stock'; ?></p>
                      <button type="button" class="btn btn-primary js-add-to-cart" data-product-id="<?php echo (int) $product['id']; ?>">Add to cart</button>
                      <a href="shop-item.php?id=<?php echo (int) $product['id']; ?>" class="btn btn-default">Details</a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="shop-filter-empty" style="display:block;">No products found right now. Please check back soon.</div>
            <?php endif; ?>

            <!-- BEGIN PAGINATOR -->
            <div class="row">
              <div class="col-md-4 col-sm-4 items-info">Items <?php echo $firstItem; ?> to <?php echo $lastItem; ?> of <?php echo $totalRows; ?> total</div>
              <div class="col-md-8 col-sm-8">
                <ul class="pagination pull-right">
                  <?php
                  $prevPage = max(1, $page - 1);
                  $nextPage = min($totalPages, $page + 1);
                  $windowStart = max(1, $page - 2);
                  $windowEnd = min($totalPages, $page + 2);
                  ?>
                  <li class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a href="<?php echo $page <= 1 ? 'javascript:;' : ('?' . http_build_query(array_merge($pageBaseParams, ['page' => $prevPage]))); ?>">&laquo;</a>
                  </li>
                  <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                    <?php if ($p === $page): ?>
                      <li><span><?php echo $p; ?></span></li>
                    <?php else: ?>
                      <li><a href="?<?php echo http_build_query(array_merge($pageBaseParams, ['page' => $p])); ?>"><?php echo $p; ?></a></li>
                    <?php endif; ?>
                  <?php endfor; ?>
                  <li class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a href="<?php echo $page >= $totalPages ? 'javascript:;' : ('?' . http_build_query(array_merge($pageBaseParams, ['page' => $nextPage]))); ?>">&raquo;</a>
                  </li>
                </ul>
              </div>
            </div>
            <!-- END PAGINATOR -->
          </div>
          <!-- END CONTENT -->
        </div>
        <!-- END SIDEBAR & CONTENT -->
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
                  <h1>Cool green dress with red bell</h1>
                  <div class="price-availability-block clearfix">
                    <div class="price">
                      <strong><span>₹</span>47.00</strong>
                      <em>₹<span>62.00</span></em>
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
    <script src="http://code.jquery.com/ui/1.10.3/jquery-ui.js" type="text/javascript"></script><!-- for slider-range -->

    <script src="assets/corporate/scripts/layout.js" type="text/javascript"></script>
    <script src="assets/pages/scripts/shop-modern.js" type="text/javascript"></script>
    <script type="text/javascript">
        jQuery(document).ready(function() {
            Layout.init();    
            Layout.initOWL();
            Layout.initTwitter();
            Layout.initImageZoom();
            Layout.initTouchspin();
            var sortSel = document.getElementById('catalog-sort');
            var showSel = document.getElementById('catalog-show');
            function applyCatalogParams() {
              if (!sortSel || !showSel) {
                return;
              }
              var params = new URLSearchParams(window.location.search || '');
              params.set('sort', sortSel.value);
              params.set('show', showSel.value);
              params.set('page', '1');
              window.location.search = params.toString();
            }
            if (sortSel) {
              sortSel.addEventListener('change', applyCatalogParams);
            }
            if (showSel) {
              showSel.addEventListener('change', applyCatalogParams);
            }
            Layout.initUniform();
            Layout.initSliderRange();
        });
    </script>
    <!-- END PAGE LEVEL JAVASCRIPTS -->
</body>
<!-- END BODY -->
</html>


