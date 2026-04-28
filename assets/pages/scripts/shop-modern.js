(function (window, document, $) {
  'use strict';

  if (!$) {
    return;
  }

  var cartState = {
    count: 0,
    subtotal: 0,
    items: []
  };

  function parsePrice(input) {
    if (typeof input === 'number') {
      return input;
    }
    if (!input) {
      return 0;
    }
    var value = String(input).replace(/[^0-9.]/g, '');
    var parsed = parseFloat(value);
    return isNaN(parsed) ? 0 : parsed;
  }

  function formatPrice(value) {
    return '$' + Number(value || 0).toFixed(2);
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function showToast(message, type) {
    var stack = document.querySelector('.shop-toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'shop-toast-stack';
      document.body.appendChild(stack);
    }

    var toast = document.createElement('div');
    toast.className = 'shop-toast ' + (type || 'info');
    toast.textContent = message;
    stack.appendChild(toast);

    window.setTimeout(function () {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(-8px)';
      window.setTimeout(function () {
        if (toast.parentNode) {
          toast.parentNode.removeChild(toast);
        }
      }, 260);
    }, 2200);
  }

  function ensureUserMenu() {
    var headerContainer = document.querySelector('.header .container');
    if (!headerContainer || document.querySelector('.shop-user-menu')) {
      return;
    }

    var wrapper = document.createElement('div');
    wrapper.className = 'shop-user-menu dropdown';
    wrapper.innerHTML = '<button class="btn btn-default dropdown-toggle" data-toggle="dropdown" type="button"><i class="fa fa-user"></i> Account <span class="caret"></span></button>' +
      '<ul class="dropdown-menu dropdown-menu-right">' +
      '<li><a href="google-login.php">Login</a></li>' +
      '<li><a href="shop-account.php">Profile</a></li>' +
      '<li><a href="shop-shopping-cart.php">Orders</a></li>' +
      '</ul>';

    var cartBlock = document.querySelector('.top-cart-block');
    if (cartBlock && cartBlock.parentNode) {
      cartBlock.parentNode.insertBefore(wrapper, cartBlock);
    }
  }

  function setStickyHeader() {
    var header = document.querySelector('.header');
    if (!header) {
      return;
    }

    function setOffsets() {
      var height = header.offsetHeight || 90;
      document.body.style.setProperty('--sticky-header-offset', height + 'px');
      document.body.style.setProperty('--sticky-header-offset-mobile', Math.max(68, height - 20) + 'px');
    }

    function onScroll() {
      if (window.pageYOffset > 18) {
        document.body.classList.add('shop-sticky-active');
      } else {
        document.body.classList.remove('shop-sticky-active');
      }
    }

    setOffsets();
    onScroll();
    window.addEventListener('resize', setOffsets);
    window.addEventListener('scroll', onScroll);
  }

  function lazyImages() {
    var images = document.querySelectorAll('img');
    for (var i = 0; i < images.length; i += 1) {
      var img = images[i];
      if (!img.getAttribute('loading')) {
        img.setAttribute('loading', 'lazy');
      }
      if (!img.getAttribute('decoding')) {
        img.setAttribute('decoding', 'async');
      }
    }
  }

  function ensureCartBadge() {
    var cartBlock = document.querySelector('.top-cart-block');
    var cartIcon = cartBlock ? cartBlock.querySelector('.fa-shopping-cart') : null;
    if (!cartBlock || !cartIcon || cartBlock.querySelector('.top-cart-badge')) {
      return;
    }

    var badge = document.createElement('span');
    badge.className = 'top-cart-badge';
    badge.textContent = '0';
    cartBlock.appendChild(badge);
  }

  function apiCart(action, data, callback) {
    var payload = $.extend({
      action: action,
      format: 'json'
    }, data || {});

    $.ajax({
      url: 'cart.php',
      method: 'POST',
      data: payload,
      dataType: 'json'
    }).done(function (response) {
      if (response && response.cart) {
        applyCartState(response.cart);
      }
      if (typeof callback === 'function') {
        callback(response || { success: false, message: 'Unexpected response.' });
      }
    }).fail(function (xhr) {
      var message = 'Cart request failed.';
      if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
        message = xhr.responseJSON.message;
      }
      if (typeof callback === 'function') {
        callback({ success: false, message: message });
      }
    });
  }

  function applyCartState(cart) {
    cartState.count = Number(cart.count || 0);
    cartState.subtotal = Number(cart.subtotal || 0);
    cartState.items = Array.isArray(cart.items) ? cart.items : [];

    var countEl = document.querySelector('.top-cart-info-count');
    if (countEl) {
      countEl.textContent = cartState.count + (cartState.count === 1 ? ' item' : ' items');
    }

    var valueEl = document.querySelector('.top-cart-info-value');
    if (valueEl) {
      valueEl.textContent = formatPrice(cartState.subtotal);
    }

    var badge = document.querySelector('.top-cart-badge');
    if (badge) {
      badge.textContent = String(cartState.count);
    }

    renderTopCartDropdown();
    renderCartPage();
  }

  function renderTopCartDropdown() {
    var list = document.querySelector('.top-cart-content .scroller');
    if (!list) {
      return;
    }

    if (!cartState.items.length) {
      list.innerHTML = '<li><strong>Your cart is empty.</strong></li>';
      return;
    }

    var html = '';
    for (var i = 0; i < cartState.items.length; i += 1) {
      var item = cartState.items[i];
      html += '<li data-product-id="' + escapeHtml(item.id) + '">' +
        '<a href="' + escapeHtml(item.item_url) + '"><img src="' + escapeHtml(item.image_path) + '" alt="' + escapeHtml(item.name) + '" width="37" height="34"></a>' +
        '<span class="cart-content-count">x ' + escapeHtml(item.qty) + '</span>' +
        '<strong><a href="' + escapeHtml(item.item_url) + '">' + escapeHtml(item.name) + '</a></strong>' +
        '<em>' + formatPrice(item.subtotal) + '</em>' +
        '<a href="javascript:void(0);" class="del-goods js-cart-remove" data-product-id="' + escapeHtml(item.id) + '">&nbsp;</a>' +
        '</li>';
    }

    list.innerHTML = html;
  }

  function renderCartPage() {
    var page = document.querySelector('.goods-page');
    if (!page) {
      return;
    }

    var table = page.querySelector('table');
    if (!table) {
      return;
    }

    var allRows = table.querySelectorAll('tr');
    for (var r = allRows.length - 1; r >= 1; r -= 1) {
      if (allRows[r] && allRows[r].parentNode) {
        allRows[r].parentNode.removeChild(allRows[r]);
      }
    }

    if (!cartState.items.length) {
      var emptyRow = document.createElement('tr');
      emptyRow.className = 'cart-row cart-empty-row';
      emptyRow.innerHTML = '<td colspan="7"><div class="shop-filter-empty" style="display:block; margin: 0;">Your cart is empty. Continue shopping to add products.</div></td>';
      table.appendChild(emptyRow);
    } else {
      for (var i = 0; i < cartState.items.length; i += 1) {
        var item = cartState.items[i];
        var row = document.createElement('tr');
        row.className = 'cart-row';
        row.setAttribute('data-product-id', item.id);
        row.innerHTML = '<td class="goods-page-image"><a href="' + escapeHtml(item.item_url) + '"><img src="' + escapeHtml(item.image_path) + '" alt="' + escapeHtml(item.name) + '"></a></td>' +
          '<td class="goods-page-description"><h3><a href="' + escapeHtml(item.item_url) + '">' + escapeHtml(item.name) + '</a></h3><em>In cart</em></td>' +
          '<td class="goods-page-ref-no">' + escapeHtml(item.sku || ('SKU-' + item.id)) + '</td>' +
          '<td class="goods-page-quantity"><div class="shop-qty-control"><button type="button" class="js-cart-page-qty" data-step="-1" data-product-id="' + escapeHtml(item.id) + '">-</button><span>' + escapeHtml(item.qty) + '</span><button type="button" class="js-cart-page-qty" data-step="1" data-product-id="' + escapeHtml(item.id) + '">+</button></div></td>' +
          '<td class="goods-page-price"><strong><span>$</span>' + Number(item.price || 0).toFixed(2) + '</strong></td>' +
          '<td class="goods-page-total"><strong><span>$</span>' + Number(item.subtotal || 0).toFixed(2) + '</strong></td>' +
          '<td class="del-goods-col"><a class="del-goods js-cart-remove" href="javascript:void(0);" data-product-id="' + escapeHtml(item.id) + '">&nbsp;</a></td>';
        table.appendChild(row);
      }
    }

    var totals = page.querySelectorAll('.shopping-total .price');
    if (totals.length >= 3) {
      totals[0].innerHTML = '<span>$</span>' + cartState.subtotal.toFixed(2);
      totals[1].innerHTML = '<span>$</span>0.00';
      totals[2].innerHTML = '<span>$</span>' + cartState.subtotal.toFixed(2);
    }
  }

  function productDataFromCard(card) {
    if (!card) {
      return null;
    }

    var titleEl = card.querySelector('h3 a');
    var imgEl = card.querySelector('img');
    var priceEl = card.querySelector('.pi-price');
    var productId = parseInt(card.getAttribute('data-product-id'), 10);

    if (!productId || productId <= 0) {
      return null;
    }

    return {
      id: productId,
      name: String(titleEl ? titleEl.textContent : 'Product').trim(),
      price: parsePrice(priceEl ? priceEl.textContent : card.getAttribute('data-product-price')),
      img: (imgEl && imgEl.getAttribute('src')) || 'assets/pages/img/products/model1.jpg',
      href: (titleEl && titleEl.getAttribute('href')) || ('shop-item.php?id=' + productId)
    };
  }

  function productDataFromDetail() {
    var page = document.querySelector('.product-page');
    if (!page) {
      return null;
    }

    var title = page.querySelector('h1');
    var price = page.querySelector('.price strong');
    var img = page.querySelector('.product-main-image img');
    var addBtn = page.querySelector('.product-page-cart .btn-primary[data-product-id]');
    var productId = addBtn ? parseInt(addBtn.getAttribute('data-product-id'), 10) : 0;

    if (!productId || productId <= 0) {
      return null;
    }

    return {
      id: productId,
      name: title ? title.textContent.trim() : 'Product',
      price: parsePrice(price ? price.textContent : 0),
      img: img ? img.getAttribute('src') : 'assets/pages/img/products/model1.jpg',
      href: window.location.pathname + window.location.search
    };
  }

  function addToCart(productId, quantity) {
    apiCart('add', {
      product_id: productId,
      qty: quantity || 1
    }, function (response) {
      if (response.success) {
        showToast('Product added to cart.', 'success');
      } else {
        showToast(response.message || 'Unable to add product.', 'info');
      }
    });
  }

  function changeCartQty(productId, step) {
    var target = null;
    for (var i = 0; i < cartState.items.length; i += 1) {
      if (String(cartState.items[i].id) === String(productId)) {
        target = cartState.items[i];
        break;
      }
    }

    var nextQty = target ? Number(target.qty) + Number(step) : Number(step);
    if (nextQty <= 0) {
      apiCart('remove', { product_id: productId }, function () {});
      return;
    }

    apiCart('update', {
      product_id: productId,
      qty: nextQty
    }, function () {});
  }

  function enhanceProductCards() {
    var cards = document.querySelectorAll('.main .product-item');

    Array.prototype.forEach.call(cards, function (card, idx) {
      card.classList.add('is-skeleton');
      window.setTimeout(function () {
        card.classList.remove('is-skeleton');
      }, 280 + (idx % 6) * 70);

      var overlay = card.querySelector('.pi-img-wrapper > div');
      var data = productDataFromCard(card);

      if (overlay && data && !overlay.querySelector('.js-quick-view')) {
        var quickBtn = document.createElement('button');
        quickBtn.type = 'button';
        quickBtn.className = 'btn btn-default js-quick-view';
        quickBtn.textContent = 'Quick View';
        quickBtn.setAttribute('data-product-id', String(data.id));
        overlay.appendChild(quickBtn);
      }
    });

    document.addEventListener('click', function (event) {
      var addBtn = event.target.closest('.js-add-to-cart');
      if (addBtn) {
        event.preventDefault();
        var id = parseInt(addBtn.getAttribute('data-product-id'), 10);
        if (id > 0) {
          addToCart(id, 1);
        }
      }

      var legacyAdd = event.target.closest('.add2cart');
      if (legacyAdd) {
        event.preventDefault();
        var card = legacyAdd.closest('.product-item');
        var product = productDataFromCard(card);
        if (product && product.id > 0) {
          addToCart(product.id, 1);
        } else {
          showToast('This product is not linked to the database item.', 'info');
        }
      }

      var removeBtn = event.target.closest('.js-cart-remove');
      if (removeBtn) {
        event.preventDefault();
        var removeId = parseInt(removeBtn.getAttribute('data-product-id'), 10);
        if (removeId > 0) {
          apiCart('remove', { product_id: removeId }, function () {});
        }
      }

      var qtyBtn = event.target.closest('.js-cart-page-qty');
      if (qtyBtn) {
        event.preventDefault();
        var qtyId = parseInt(qtyBtn.getAttribute('data-product-id'), 10);
        var step = parseInt(qtyBtn.getAttribute('data-step'), 10);
        if (qtyId > 0 && step !== 0) {
          changeCartQty(qtyId, step);
        }
      }
    });
  }

  function ensureQuickViewModal() {
    if (document.querySelector('#shopQuickViewModal')) {
      return;
    }

    var modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = 'shopQuickViewModal';
    modal.tabIndex = -1;
    modal.innerHTML = '<div class="modal-dialog modal-lg"><div class="modal-content">' +
      '<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title js-qv-title">Product</h4></div>' +
      '<div class="modal-body"><div class="row">' +
      '<div class="col-sm-5"><img class="img-responsive js-qv-image" src="" alt=""></div>' +
      '<div class="col-sm-7"><p class="h3 js-qv-price" style="color:#f36f21;font-weight:700;"></p><p class="js-qv-meta text-muted"></p>' +
      '<div class="description js-qv-description">Explore this product and see more details on the product page.</div>' +
      '<div style="margin-top:16px;"><button type="button" class="btn btn-primary js-qv-add">Add to Cart</button> <a href="#" class="btn btn-default js-qv-link">View Details</a></div>' +
      '</div></div></div></div></div>';
    document.body.appendChild(modal);

    var quickState = { product: null };

    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('.js-quick-view');
      if (!trigger) {
        return;
      }

      event.preventDefault();
      var card = trigger.closest('.product-item');
      var product = productDataFromCard(card);
      if (!product) {
        return;
      }

      quickState.product = product;
      modal.querySelector('.js-qv-title').textContent = product.name;
      modal.querySelector('.js-qv-price').textContent = formatPrice(product.price);
      modal.querySelector('.js-qv-image').src = product.img;
      modal.querySelector('.js-qv-image').alt = product.name;
      modal.querySelector('.js-qv-link').href = product.href;
      modal.querySelector('.js-qv-meta').textContent = 'Product ID: ' + product.id;
      $('#shopQuickViewModal').modal('show');
    });

    modal.addEventListener('click', function (event) {
      var add = event.target.closest('.js-qv-add');
      if (add && quickState.product) {
        addToCart(quickState.product.id, 1);
      }
    });
  }

  function enhanceDetailPage() {
    var page = document.querySelector('.product-page');
    if (!page) {
      return;
    }

    var addBtn = page.querySelector('.product-page-cart .btn-primary[data-product-id]');
    if (addBtn) {
      addBtn.addEventListener('click', function (event) {
        event.preventDefault();
        var product = productDataFromDetail();
        if (!product) {
          return;
        }
        var qtyInput = page.querySelector('.product-quantity input');
        var qty = qtyInput ? parseInt(qtyInput.value, 10) : 1;
        if (!qty || qty < 1) {
          qty = 1;
        }
        addToCart(product.id, qty);
      });
    }

    var cartBar = page.querySelector('.product-page-cart');
    if (cartBar && window.innerWidth <= 767) {
      cartBar.classList.add('mobile-sticky-cart');
    }

    window.addEventListener('resize', function () {
      if (!cartBar) {
        return;
      }
      if (window.innerWidth <= 767) {
        cartBar.classList.add('mobile-sticky-cart');
      } else {
        cartBar.classList.remove('mobile-sticky-cart');
      }
    });
  }

  function enhanceSearchSuggestions() {
    var input = document.querySelector('.menu-search .search-box input[name="search"]');
    if (!input) {
      return;
    }

    var form = input.closest('form');
    var suggestions = [];

    Array.prototype.forEach.call(document.querySelectorAll('.product-item h3 a'), function (a) {
      var name = a.textContent.trim();
      if (!name) {
        return;
      }
      suggestions.push({
        name: name,
        href: a.getAttribute('href') || 'shop-product-list.php'
      });
    });

    var unique = [];
    var index = {};
    suggestions.forEach(function (entry) {
      var key = entry.name.toLowerCase();
      if (!index[key]) {
        index[key] = true;
        unique.push(entry);
      }
    });

    var list = document.createElement('ul');
    list.className = 'search-suggest-list';
    list.style.display = 'none';
    input.parentNode.parentNode.appendChild(list);

    function hideList() {
      list.style.display = 'none';
      list.innerHTML = '';
    }

    function renderList(value) {
      var query = value.trim().toLowerCase();
      if (!query) {
        hideList();
        return;
      }

      var matches = unique.filter(function (entry) {
        return entry.name.toLowerCase().indexOf(query) !== -1;
      }).slice(0, 6);

      if (!matches.length) {
        list.innerHTML = '<li><button type="button" disabled>No products found</button></li>';
        list.style.display = 'block';
        return;
      }

      var html = '';
      matches.forEach(function (entry) {
        html += '<li><a href="' + escapeHtml(entry.href) + '">' + escapeHtml(entry.name) + '</a></li>';
      });
      list.innerHTML = html;
      list.style.display = 'block';
    }

    input.addEventListener('input', function () {
      renderList(input.value);
    });

    document.addEventListener('click', function (event) {
      if (!list.contains(event.target) && event.target !== input) {
        hideList();
      }
    });

    if (form) {
      form.addEventListener('submit', function (event) {
        var first = list.querySelector('a');
        if (first && input.value.trim() !== '') {
          event.preventDefault();
          window.location.href = first.getAttribute('href');
        }
      });
    }
  }

  function enhanceFilters() {
    var grid = document.querySelector('.js-catalog-grid');
    if (!grid) {
      return;
    }

    Array.prototype.forEach.call(document.querySelectorAll('.product-list:not(.js-catalog-grid)'), function (row) {
      if (row.closest('#product-pop-up')) {
        return;
      }
      if (row.querySelector('.product-item')) {
        row.style.display = 'none';
      }
    });

    var sidebar = document.querySelector('.sidebar-filter');
    if (!sidebar) {
      return;
    }

    sidebar.classList.add('shop-filter-block');

    var cards = Array.prototype.slice.call(grid.querySelectorAll('.product-item'));
    if (!cards.length) {
      return;
    }

    var categories = {};
    var min = Number.POSITIVE_INFINITY;
    var max = 0;

    cards.forEach(function (card) {
      var category = card.getAttribute('data-category') || 'General';
      categories[category] = true;
      var price = parsePrice(card.getAttribute('data-product-price') || (card.querySelector('.pi-price') && card.querySelector('.pi-price').textContent));
      min = Math.min(min, price);
      max = Math.max(max, price);
    });

    if (!isFinite(min)) {
      min = 0;
      max = 1000;
    }

    var categoriesMarkup = Object.keys(categories).map(function (name) {
      var escaped = name.replace(/"/g, '&quot;');
      return '<option value="' + escaped + '">' + name + '</option>';
    }).join('');

    var html = '<h2>Filter</h2>' +
      '<div class="form-group"><label>Category</label><select class="form-control js-filter-category"><option value="all">All categories</option>' + categoriesMarkup + '</select></div>' +
      '<div class="form-group"><label>Max price: <strong class="js-filter-price-label">' + formatPrice(max) + '</strong></label><input class="form-control js-filter-price" type="range" min="' + Math.floor(min) + '" max="' + Math.ceil(max) + '" step="1" value="' + Math.ceil(max) + '"></div>' +
      '<div class="checkbox-list"><label><input type="checkbox" class="js-filter-stock"> In Stock only</label></div>' +
      '<button type="button" class="btn btn-default js-filter-reset">Reset filters</button>';

    sidebar.innerHTML = html;

    if (!document.querySelector('.shop-filter-empty')) {
      var empty = document.createElement('div');
      empty.className = 'shop-filter-empty js-filter-empty';
      empty.textContent = 'No products match your current filter. Try widening the range or resetting filters.';
      grid.parentNode.insertBefore(empty, grid.nextSibling);
    }

    var categoryField = sidebar.querySelector('.js-filter-category');
    var priceField = sidebar.querySelector('.js-filter-price');
    var priceLabel = sidebar.querySelector('.js-filter-price-label');
    var stockField = sidebar.querySelector('.js-filter-stock');
    var resetBtn = sidebar.querySelector('.js-filter-reset');
    var emptyState = document.querySelector('.js-filter-empty');

    function applyFilter() {
      var selectedCategory = categoryField.value;
      var maxPrice = Number(priceField.value);
      var stockOnly = stockField.checked;
      priceLabel.textContent = formatPrice(maxPrice);

      var visible = 0;
      cards.forEach(function (card) {
        var cardCategory = card.getAttribute('data-category') || 'General';
        var cardPrice = parsePrice(card.getAttribute('data-product-price') || (card.querySelector('.pi-price') && card.querySelector('.pi-price').textContent));
        var cardStock = Number(card.getAttribute('data-stock') || 0);

        var categoryOk = selectedCategory === 'all' || selectedCategory === cardCategory;
        var priceOk = cardPrice <= maxPrice;
        var stockOk = !stockOnly || cardStock > 0;

        var show = categoryOk && priceOk && stockOk;
        var col = card.closest('[class*="col-"]') || card;
        col.style.display = show ? '' : 'none';
        if (show) {
          visible += 1;
        }
      });

      if (emptyState) {
        emptyState.style.display = visible ? 'none' : 'block';
      }
    }

    categoryField.addEventListener('change', applyFilter);
    priceField.addEventListener('input', applyFilter);
    stockField.addEventListener('change', applyFilter);

    resetBtn.addEventListener('click', function () {
      categoryField.value = 'all';
      priceField.value = String(Math.ceil(max));
      stockField.checked = false;
      applyFilter();
    });

    applyFilter();
  }

  function enhanceMobileSidebar() {
    var sidebar = document.querySelector('.sidebar');
    var content = document.querySelector('.main .container .row.margin-bottom-40');
    if (!sidebar || !content || document.querySelector('.js-sidebar-toggle')) {
      return;
    }

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-default btn-block visible-xs visible-sm js-sidebar-toggle';
    btn.textContent = 'Toggle Filters & Categories';
    content.insertBefore(btn, sidebar);

    btn.addEventListener('click', function () {
      if (window.innerWidth > 991) {
        return;
      }
      if (sidebar.style.display === 'none' || !sidebar.style.display) {
        sidebar.style.display = 'block';
      } else {
        sidebar.style.display = 'none';
      }
    });

    if (window.innerWidth <= 991) {
      sidebar.style.display = 'none';
    }

    window.addEventListener('resize', function () {
      if (window.innerWidth > 991) {
        sidebar.style.display = '';
      }
    });
  }

  function enhanceHeroButtons() {
    var buttons = document.querySelectorAll('.carousel-btn[href="#"], .carousel-btn[data-shop-cta="true"]');
    Array.prototype.forEach.call(buttons, function (btn) {
      btn.classList.add('shop-cta-btn');
      btn.setAttribute('href', '#featured-products');
      btn.textContent = 'Shop Now';
    });
  }

  function initBootstrapFixes() {
    if ($.fn && typeof $.fn.dropdown === 'function') {
      $('.dropdown-toggle').dropdown();
    }
  }

  function initTopCartToggle() {
    var cartIcon = document.querySelector('.top-cart-block .fa-shopping-cart');
    var cartContent = document.querySelector('.top-cart-content');
    if (!cartIcon || !cartContent) {
      return;
    }

    cartIcon.addEventListener('click', function (event) {
      event.preventDefault();
      var isVisible = cartContent.style.display === 'block';
      cartContent.style.display = isVisible ? 'none' : 'block';
    });

    document.addEventListener('click', function (event) {
      if (!event.target.closest('.top-cart-block')) {
        cartContent.style.display = 'none';
      }
    });
  }

  function refreshCart() {
    apiCart('summary', {}, function () {});
  }

  function init() {
    ensureUserMenu();
    ensureCartBadge();
    setStickyHeader();
    lazyImages();
    enhanceProductCards();
    ensureQuickViewModal();
    enhanceDetailPage();
    enhanceSearchSuggestions();
    enhanceFilters();
    enhanceMobileSidebar();
    enhanceHeroButtons();
    initBootstrapFixes();
    initTopCartToggle();
    refreshCart();
  }

  $(document).ready(function () {
    init();
  });
})(window, document, window.jQuery);
