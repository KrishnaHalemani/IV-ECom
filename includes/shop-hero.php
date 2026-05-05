<?php
declare(strict_types=1);
if (!isset($heroSlides) || !is_array($heroSlides) || $heroSlides === []) {
    return;
}
?>
<!-- BEGIN SLIDER -->
<div class="page-slider margin-bottom-35">
  <div id="carousel-example-generic" class="carousel slide carousel-slider">
    <ol class="carousel-indicators">
      <?php foreach ($heroSlides as $i => $slide): ?>
        <li data-target="#carousel-example-generic" data-slide-to="<?php echo (int) $i; ?>" class="<?php echo $i === 0 ? 'active' : ''; ?>"></li>
      <?php endforeach; ?>
    </ol>
    <div class="carousel-inner" role="listbox">
      <?php foreach ($heroSlides as $i => $slide): ?>
        <?php
        $imagePath = trim((string) ($slide['image'] ?? ($slide['image_path'] ?? '')));
        if ($imagePath === '') {
            $imagePath = 'assets/pages/img/shop-slider/slide1/bg.jpg';
        }
        $title = trim((string) ($slide['title'] ?? ''));
        $subtitle = trim((string) ($slide['subtitle'] ?? ''));
        $ctaText = trim((string) ($slide['button_text'] ?? 'Shop Now'));
        $ctaLink = trim((string) ($slide['button_link'] ?? '#featured-products'));
        ?>
        <div class="item carousel-item-admin <?php echo $i === 0 ? 'active' : ''; ?>" style="background: url('<?php echo fe_h($imagePath); ?>') center center no-repeat; background-size: cover;">
          <div class="container">
            <div class="carousel-position-four text-center">
              <?php if ($title !== ''): ?><h2 class="margin-bottom-20 animate-delay carousel-title-v3 border-bottom-title text-uppercase"><?php echo fe_h($title); ?></h2><?php endif; ?>
              <?php if ($subtitle !== ''): ?><p class="carousel-subtitle-v3 margin-bottom-15"><?php echo fe_h($subtitle); ?></p><?php endif; ?>
              <a class="carousel-btn" href="<?php echo fe_h($ctaLink); ?>" data-shop-cta="true"><?php echo fe_h($ctaText); ?></a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <a class="left carousel-control carousel-control-shop" href="#carousel-example-generic" role="button" data-slide="prev"><i class="fa fa-angle-left"></i></a>
    <a class="right carousel-control carousel-control-shop" href="#carousel-example-generic" role="button" data-slide="next"><i class="fa fa-angle-right"></i></a>
  </div>
</div>
<!-- END SLIDER -->