<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/site-content.php';

$dbHeader = get_db_connection();
$siteContentHeader = fetch_site_content_map($dbHeader);
$pagesMenuLabel = htmlspecialchars(site_content_value($siteContentHeader, 'nav_pages_label', 'Pages'), ENT_QUOTES, 'UTF-8');
?>
<!-- BEGIN HEADER -->
<div class="header">
  <div class="container">
    <a class="site-logo" href="shop-index.php"><img src="assets/corporate/img/logos/BattleRockLogo.jpeg" alt="Shop"></a>

    <a href="javascript:void(0);" class="mobi-toggler"><i class="fa fa-bars"></i></a>

    <div class="header-navigation">
      <ul>
        <li class="dropdown">
          <a class="dropdown-toggle" data-toggle="dropdown" data-target="#" href="javascript:;"><?php echo $pagesMenuLabel; ?></a>
          <ul class="dropdown-menu">
            <li><a href="shop-about.php">About</a></li>
            <li><a href="shop-contacts.php">Contacts</a></li>
            <li><a href="shop-faq.php">FAQ</a></li>
            <li><a href="shop-privacy-policy.php">Privacy Policy</a></li>
            <li><a href="shop-terms-conditions-page.php">Terms &amp; Conditions</a></li>
          </ul>
        </li>
        <li class="menu-search">
          <span class="sep"></span>
          <i class="fa fa-search search-btn"></i>
          <div class="search-box">
            <form action="form-submit.php" method="post">
              <div class="input-group">
                <input type="text" placeholder="Search" class="form-control" name="search">
                <span class="input-group-btn"><button class="btn btn-primary" type="submit">Search</button></span>
              </div>
            </form>
          </div>
        </li>
      </ul>
    </div>

    <div class="top-cart-block">
      <div class="top-cart-info">
        <a href="javascript:void(0);" class="top-cart-info-count">0 items</a>
        <a href="javascript:void(0);" class="top-cart-info-value">$0.00</a>
      </div>
      <i class="fa fa-shopping-cart"></i>
      <div class="top-cart-content-wrapper">
        <div class="top-cart-content">
          <ul class="scroller" style="height:250px;"></ul>
          <div class="text-right">
            <a href="shop-shopping-cart.php" class="btn btn-default">View Cart</a>
            <a href="shop-checkout.php" class="btn btn-primary">Checkout</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Header END -->
