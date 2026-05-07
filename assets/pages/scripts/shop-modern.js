(function (window, document, $) {
  'use strict';

  if (!$) {
    return;
  }

  var cartState = {
    count: 0,
    subtotal: 0,
    discount: 0,
    total: 0,
    coupon: null,
    items: []
  };
  var RUPEE = '\u20B9';
  var authState = {
    logged_in: false,
    user_id: 0,
    name: '',
    email: '',
    account_url: 'account.php',
    login_url: 'login.php',
    register_url: 'register.php',
    logout_url: 'user-logout.php'
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
    return RUPEE + ' ' + Number(value || 0).toFixed(2);
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
    wrapper.innerHTML = '<button class="btn btn-default dropdown-toggle js-user-menu-button" data-toggle="dropdown" type="button"><i class="fa fa-user"></i> Account <span class="caret"></span></button>' +
      '<ul class="dropdown-menu dropdown-menu-right js-user-menu-list">' +
      '</ul>';

    var cartBlock = document.querySelector('.top-cart-block');
    if (cartBlock && cartBlock.parentNode) {
      cartBlock.parentNode.insertBefore(wrapper, cartBlock);
    }

    if (cartBlock && cartBlock.parentNode && !document.querySelector('.shop-orders-link')) {
      var ordersLink = document.createElement('a');
      ordersLink.className = 'btn btn-default shop-orders-link';
      ordersLink.href = (authState.account_url || 'account.php') + '#orders';
      ordersLink.innerHTML = '<i class="fa fa-truck"></i> My Orders';
      cartBlock.parentNode.insertBefore(ordersLink, cartBlock);
    }
  }

  function applyAuthState(auth) {
    if (!auth || typeof auth !== 'object') {
      return;
    }

    authState = $.extend({}, authState, auth);

    var navList = document.querySelector('.additional-nav ul');
    if (navList) {
      if (authState.logged_in) {
        navList.innerHTML = '' +
          '<li><a href="' + escapeHtml(authState.account_url || 'account.php') + '">My Account</a></li>' +
          '<li><a href="shop-wishlist.php">My Wishlist</a></li>' +
          '<li><a href="shop-checkout.php">Checkout</a></li>' +
          '<li><a href="' + escapeHtml(authState.logout_url || 'user-logout.php') + '">Logout</a></li>';
      } else {
        navList.innerHTML = '' +
          '<li><a href="shop-wishlist.php">My Wishlist</a></li>' +
          '<li><a href="shop-checkout.php">Checkout</a></li>' +
          '<li><a href="' + escapeHtml(authState.login_url || 'login.php') + '">Login</a></li>' +
          '<li><a href="' + escapeHtml(authState.register_url || 'register.php') + '">Register</a></li>';
      }
    }

    var button = document.querySelector('.js-user-menu-button');
    var list = document.querySelector('.js-user-menu-list');
    if (button) {
      if (authState.logged_in) {
        var displayName = String(authState.name || 'Account').trim();
        button.innerHTML = '<i class="fa fa-user"></i> ' + escapeHtml(displayName) + ' <span class="caret"></span>';
      } else {
        button.innerHTML = '<i class="fa fa-user"></i> Account <span class="caret"></span>';
      }
    }
    if (list) {
      if (authState.logged_in) {
        list.innerHTML = '' +
          '<li><a href="' + escapeHtml(authState.account_url || 'account.php') + '">My Account</a></li>' +
          '<li><a href="' + escapeHtml((authState.account_url || 'account.php') + '#orders') + '">My Orders</a></li>' +
          '<li><a href="shop-shopping-cart.php">Cart</a></li>' +
          '<li><a href="' + escapeHtml(authState.logout_url || 'user-logout.php') + '">Logout</a></li>';
      } else {
        list.innerHTML = '' +
          '<li><a href="' + escapeHtml(authState.login_url || 'login.php') + '">Login</a></li>' +
          '<li><a href="' + escapeHtml(authState.register_url || 'register.php') + '">Register</a></li>';
      }
    }

    var ordersBtn = document.querySelector('.shop-orders-link');
    if (ordersBtn) {
      if (authState.logged_in) {
        ordersBtn.style.display = 'inline-block';
        ordersBtn.setAttribute('href', (authState.account_url || 'account.php') + '#orders');
      } else {
        ordersBtn.style.display = 'none';
      }
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
      if (response && response.auth) {
        applyAuthState(response.auth);
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
    cartState.discount = Number(cart.discount || 0);
    cartState.total = Number(typeof cart.total !== 'undefined' ? cart.total : cartState.subtotal);
    cartState.coupon = cart && cart.coupon ? cart.coupon : null;
    cartState.items = Array.isArray(cart.items) ? cart.items : [];

    var countEl = document.querySelector('.top-cart-info-count');
    if (countEl) {
      countEl.textContent = cartState.count + (cartState.count === 1 ? ' item' : ' items');
    }

    var valueEl = document.querySelector('.top-cart-info-value');
    if (valueEl) {
      valueEl.textContent = formatPrice(cartState.subtotal);
    }

    // Keep only one value label in the compact header strip.
    var valueEls = document.querySelectorAll('.top-cart-info-value');
    if (valueEls.length > 1) {
      for (var v = 1; v < valueEls.length; v += 1) {
        if (valueEls[v] && valueEls[v].parentNode) {
          valueEls[v].parentNode.removeChild(valueEls[v]);
        }
      }
    }

    var badge = document.querySelector('.top-cart-badge');
    if (badge) {
      badge.textContent = String(cartState.count);
    }

    renderTopCartDropdown();
    renderCartPage();
    renderCheckoutPage();
  }

  function renderTopCartDropdown() {
    var list = document.querySelector('.top-cart-content .scroller');
    if (!list) {
      return;
    }

    var summaryHtml = '<li class="top-cart-summary" style="padding:10px 0 12px;border-bottom:1px solid #e9edf1;margin-bottom:6px;">' +
      '<strong>' + cartState.count + (cartState.count === 1 ? ' item' : ' items') + '</strong> | ' +
      '<strong>' + formatPrice(cartState.subtotal) + '</strong> | ' +
      '<strong>' + RUPEE + ' ' + Number(cartState.subtotal || 0).toFixed(2) + '</strong>' +
      '</li>';

    if (!cartState.items.length) {
      list.innerHTML = summaryHtml + '<li><strong>Your cart is empty.</strong></li>';
      return;
    }

    var html = summaryHtml;
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
          '<td class="goods-page-price"><strong><span>' + RUPEE + '</span>' + Number(item.price || 0).toFixed(2) + '</strong></td>' +
          '<td class="goods-page-total"><strong><span>' + RUPEE + '</span>' + Number(item.subtotal || 0).toFixed(2) + '</strong></td>' +
          '<td class="del-goods-col"><a class="del-goods js-cart-remove" href="javascript:void(0);" data-product-id="' + escapeHtml(item.id) + '">&nbsp;</a></td>';
        table.appendChild(row);
      }
    }

    var totals = page.querySelectorAll('.shopping-total .price');
    if (totals.length >= 3) {
      totals[0].innerHTML = '<span>' + RUPEE + '</span>' + cartState.subtotal.toFixed(2);
      totals[1].innerHTML = '<span>' + RUPEE + '</span>0.00';
      totals[2].innerHTML = '<span>' + RUPEE + '</span>' + cartState.subtotal.toFixed(2);
    }
  }

  function renderCheckoutPage() {
    var confirmSection = document.querySelector('#confirm-content');
    if (!confirmSection) {
      return;
    }

    var table = confirmSection.querySelector('table');
    if (table) {
      var headerRow = table.querySelector('tr');
      table.innerHTML = '';
      if (headerRow) {
        table.appendChild(headerRow);
      }

      if (!cartState.items.length) {
        var empty = document.createElement('tr');
        empty.innerHTML = '<td colspan="6"><div class="shop-filter-empty" style="display:block;margin:0;">Your cart is empty.</div></td>';
        table.appendChild(empty);
      } else {
        for (var i = 0; i < cartState.items.length; i += 1) {
          var item = cartState.items[i];
          var row = document.createElement('tr');
          row.innerHTML = '' +
            '<td class="checkout-image"><a href="' + escapeHtml(item.item_url) + '"><img src="' + escapeHtml(item.image_path) + '" alt="' + escapeHtml(item.name) + '"></a></td>' +
            '<td class="checkout-description"><h3><a href="' + escapeHtml(item.item_url) + '">' + escapeHtml(item.name) + '</a></h3><em>From your cart</em></td>' +
            '<td class="checkout-model">' + escapeHtml(item.sku || ('SKU-' + item.id)) + '</td>' +
            '<td class="checkout-quantity">' + escapeHtml(item.qty) + '</td>' +
            '<td class="checkout-price"><strong><span>' + RUPEE + '</span>' + Number(item.price || 0).toFixed(2) + '</strong></td>' +
            '<td class="checkout-total"><strong><span>' + RUPEE + '</span>' + Number(item.subtotal || 0).toFixed(2) + '</strong></td>';
          table.appendChild(row);
        }
      }
    }

    var subtotalEl = confirmSection.querySelector('.js-checkout-subtotal');
    var shippingEl = confirmSection.querySelector('.js-checkout-shipping');
    var discountEl = confirmSection.querySelector('.js-checkout-discount');
    var vatEl = confirmSection.querySelector('.js-checkout-vat');
    var totalEl = confirmSection.querySelector('.js-checkout-total');

    if (subtotalEl) {
      subtotalEl.innerHTML = '<span>' + RUPEE + '</span>' + cartState.subtotal.toFixed(2);
    }
    if (shippingEl) {
      shippingEl.innerHTML = '<span>' + RUPEE + '</span>0.00';
    }
    if (discountEl) {
      discountEl.innerHTML = '<span>' + RUPEE + '</span>' + cartState.discount.toFixed(2);
    }
    if (vatEl) {
      vatEl.innerHTML = '<span>' + RUPEE + '</span>0.00';
    }
    if (totalEl) {
      totalEl.innerHTML = '<span>' + RUPEE + '</span>' + cartState.total.toFixed(2);
    }

    var couponFeedback = confirmSection.querySelector('#coupon-feedback');
    if (couponFeedback) {
      if (cartState.coupon && cartState.coupon.code) {
        couponFeedback.className = 'text-success';
        couponFeedback.textContent = 'Coupon ' + String(cartState.coupon.code) + ' applied. Discount: ' + RUPEE + Number(cartState.discount || 0).toFixed(2);
      } else {
        couponFeedback.className = 'text-muted';
        couponFeedback.textContent = '';
      }
    }

    var confirmBtn = document.querySelector('#button-confirm');
    if (confirmBtn) {
      confirmBtn.disabled = !cartState.items.length;
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
      var image = event.target.closest('.product-item .pi-img-wrapper img');
      if (image) {
        var cardFromImage = image.closest('.product-item');
        var titleLink = cardFromImage ? cardFromImage.querySelector('h3 a') : null;
        var targetHref = titleLink ? String(titleLink.getAttribute('href') || '').trim() : '';
        // Keep existing controls working; navigate only when clicking the product image itself.
        if (targetHref && targetHref !== '#' && targetHref.toLowerCase().indexOf('javascript:') !== 0) {
          event.preventDefault();
          window.location.href = targetHref;
          return;
        }
      }

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

    var filterTitle = sidebar.getAttribute('data-filter-title') || 'Filter';
    var allCategoriesLabel = sidebar.getAttribute('data-filter-all-categories-label') || 'All categories';
    var html = '<h2>' + escapeHtml(filterTitle) + '</h2>' +
      '<div class="form-group"><label>Category</label><select class="form-control js-filter-category"><option value="all">' + escapeHtml(allCategoriesLabel) + '</option>' + categoriesMarkup + '</select></div>' +
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
    var buttons = document.querySelectorAll('.carousel-btn, .carousel-btn[data-shop-cta="true"]');
    Array.prototype.forEach.call(buttons, function (btn) {
      btn.classList.add('shop-cta-btn');
      var href = (btn.getAttribute('href') || '').trim();
      if (href === '' || href === '#') {
        btn.setAttribute('href', '#featured-products');
      }
      if ((btn.textContent || '').trim() === '') {
        btn.textContent = 'Shop Now';
      }
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

  function setupCouponActions() {
    var applyBtn = document.querySelector('#apply-coupon-btn');
    var input = document.querySelector('#coupon-code');
    var feedback = document.querySelector('#coupon-feedback');
    if (!applyBtn || !input || !feedback) {
      return;
    }

    function setFeedback(message, cssClass) {
      feedback.textContent = message || '';
      feedback.className = cssClass || 'text-muted';
    }

    applyBtn.addEventListener('click', function () {
      var code = String(input.value || '').trim();
      if (!code) {
        apiCart('remove_coupon', {}, function (response) {
          if (response && response.success) {
            setFeedback('Coupon removed.', 'text-muted');
          } else {
            setFeedback((response && response.message) || 'Could not remove coupon.', 'text-danger');
          }
        });
        return;
      }

      setFeedback('Validating coupon...', 'text-info');
      apiCart('apply_coupon', { coupon_code: code }, function (response) {
        if (response && response.success) {
          setFeedback(response.message || 'Coupon applied.', 'text-success');
        } else {
          setFeedback((response && response.message) || 'Invalid or expired coupon code.', 'text-danger');
        }
      });
    });
  }

  function hydrateStaticProductListsFromDb() {
    var rows = Array.prototype.slice.call(document.querySelectorAll('.product-list'));
    if (!rows.length) {
      return;
    }

    // If the page already has DB-bound cards, skip hydration.
    var hasDynamicCards = document.querySelector('.product-item[data-product-id]');
    if (hasDynamicCards) {
      return;
    }

    var params = new URLSearchParams(window.location.search || '');
    var category = params.get('category') || '';
    var totalSlots = 0;
    rows.forEach(function (row) {
      totalSlots += row.querySelectorAll('.col-md-4, .col-md-3, .col-sm-6').length || 0;
    });
    if (totalSlots <= 0) {
      totalSlots = 9;
    }
    if (totalSlots > 18) {
      totalSlots = 18;
    }

    $.ajax({
      url: 'products-feed.php',
      method: 'GET',
      dataType: 'json',
      data: {
        category: category,
        limit: totalSlots
      }
    }).done(function (response) {
      if (!response || !response.success || !Array.isArray(response.products) || !response.products.length) {
        return;
      }

      var flatCells = [];
      rows.forEach(function (row) {
        var cells = row.querySelectorAll('.col-md-4, .col-md-3, .col-sm-6, .col-xs-12');
        Array.prototype.forEach.call(cells, function (cell) {
          flatCells.push(cell);
        });
      });

      for (var i = 0; i < flatCells.length; i += 1) {
        if (!response.products[i]) {
          flatCells[i].style.display = 'none';
          continue;
        }

        var p = response.products[i];
        var inStock = Number(p.stock_qty || 0) > 0;
        flatCells[i].style.display = '';
        flatCells[i].innerHTML =
          '<div class="product-item" data-product-id="' + escapeHtml(p.id) + '" data-product-price="' + escapeHtml(Number(p.price || 0).toFixed(2)) + '" data-stock="' + escapeHtml(p.stock_qty) + '" data-category="' + escapeHtml(p.category_name || 'General') + '">' +
            '<div class="pi-img-wrapper">' +
              '<img src="' + escapeHtml(p.image_path) + '" class="img-responsive" alt="' + escapeHtml(p.name) + '" loading="lazy" decoding="async">' +
              '<div>' +
                '<a href="' + escapeHtml(p.image_path) + '" class="btn btn-default fancybox-button">Zoom</a>' +
                '<a href="' + escapeHtml(p.item_url || ('shop-item.php?id=' + p.id)) + '" class="btn btn-default js-quick-view">Quick View</a>' +
              '</div>' +
            '</div>' +
            '<h3><a href="' + escapeHtml(p.item_url || ('shop-item.php?id=' + p.id)) + '">' + escapeHtml(p.name) + '</a></h3>' +
            '<div class="pi-price">' + formatPrice(p.price) + '</div>' +
            '<p class="product-meta">' + escapeHtml(p.category_name || 'General') + ' | ' + (inStock ? 'In Stock' : 'Out of Stock') + '</p>' +
            '<button type="button" class="btn btn-primary js-add-to-cart" data-product-id="' + escapeHtml(p.id) + '"' + (inStock ? '' : ' disabled') + '>Add to cart</button> ' +
            '<a href="' + escapeHtml(p.item_url || ('shop-item.php?id=' + p.id)) + '" class="btn btn-default">Details</a>' +
          '</div>';
      }
    });
  }

  function normalizeStaticCurrencyLabels() {
    var selectors = [
      '.top-cart-info-value',
      '.cart-content-count + strong + em',
      '.goods-page-price strong',
      '.goods-page-total strong',
      '.checkout-price strong',
      '.checkout-total strong',
      '.shopping-total .price',
      '.checkout-total-block .price',
      '.product-price strong',
      '.price strong',
      '.price em'
    ];

    selectors.forEach(function (selector) {
      var nodes = document.querySelectorAll(selector);
      nodes.forEach(function (node) {
        var text = String(node.textContent || '');
        if (text.indexOf('$') !== -1 || text.indexOf('INR') !== -1 || text.indexOf('Rs.') !== -1) {
          node.textContent = text
            .replace(/\$/g, RUPEE + ' ')
            .replace(/\bINR\b/g, RUPEE)
            .replace(/\bRs\.\b/g, RUPEE)
            .replace(/\s{2,}/g, ' ');
        }
      });
    });
  }

  function loadDynamicCategoryNav() {
    var nav = document.querySelector('.header-navigation > ul');
    if (!nav || nav.getAttribute('data-dynamic-categories') === '1') {
      return;
    }

    $.ajax({
      url: 'categories-nav.php',
      method: 'GET',
      dataType: 'json'
    }).done(function (response) {
      if (!response || !response.success || !Array.isArray(response.categories) || !response.categories.length) {
        return;
      }

      var fixedLabels = {
        'pages': true
      };

      var existingItems = Array.prototype.slice.call(nav.children);
      for (var i = 0; i < existingItems.length; i += 1) {
        var item = existingItems[i];
        var link = item.querySelector(':scope > a');
        var label = link ? String(link.textContent || '').trim().toLowerCase() : '';
        var isSearch = item.classList.contains('menu-search');
        if (!isSearch && !fixedLabels[label]) {
          nav.removeChild(item);
        }
      }

      var insertBefore = nav.querySelector('li.menu-search');
      response.categories.slice(0, 5).forEach(function (cat) {
        var name = String(cat.name || '').trim();
        var url = String(cat.url || '').trim();
        if (!name || !url) {
          return;
        }

        // Product category "General" should act as Home in top navigation.
        if (name.toLowerCase() === 'general') {
          name = 'HOME';
          url = 'shop-index.php';
        }

      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = url;
      a.textContent = name;
      li.appendChild(a);
      nav.insertBefore(li, insertBefore || null);
      });

      nav.setAttribute('data-dynamic-categories', '1');
    });
  }

  function applyCheckoutPrefillSelects() {
    var prefill = window.checkoutProfilePrefill || {};
    var country = String(prefill.country || '').trim().toLowerCase();
    var state = String(prefill.state || '').trim().toLowerCase();

    function selectByText(selectId, expectedText) {
      if (!expectedText) {
        return;
      }
      var select = document.querySelector(selectId);
      if (!select || !select.options) {
        return;
      }
      for (var i = 0; i < select.options.length; i += 1) {
        var option = select.options[i];
        if (String(option.text || '').trim().toLowerCase() === expectedText) {
          select.value = option.value;
          break;
        }
      }
    }

    selectByText('#country', country);
    selectByText('#region-state', state);
  }

  function handleCheckout() {
    var confirmBtn = document.querySelector('#button-confirm');
    if (!confirmBtn) {
      return;
    }

    function selectedPaymentMethod() {
      var selected = document.querySelector('input[name="payment_method"]:checked');
      return selected ? selected.value : 'cod';
    }

    function postJson(url, payload, callback) {
      $.ajax({
        url: url,
        method: 'POST',
        data: payload || {},
        dataType: 'json'
      }).done(function (response) {
        callback(response || { success: false, message: 'Unexpected response.' });
      }).fail(function (xhr) {
        var message = 'Request failed.';
        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
          message = xhr.responseJSON.message;
        }
        callback({ success: false, message: message });
      });
    }

    function saveCheckoutProfile(callback) {
      var countrySelect = document.querySelector('#country');
      var stateSelect = document.querySelector('#region-state');
      var countryValue = ($('#country').val() || '').trim();
      var stateValue = ($('#region-state').val() || '').trim();

      // Backward compatibility: if these are still selects on some pages, save selected label text.
      if (countrySelect && countrySelect.tagName === 'SELECT' && countrySelect.selectedOptions && countrySelect.selectedOptions[0]) {
        countryValue = String(countrySelect.selectedOptions[0].text || '').trim();
      }
      if (stateSelect && stateSelect.tagName === 'SELECT' && stateSelect.selectedOptions && stateSelect.selectedOptions[0]) {
        stateValue = String(stateSelect.selectedOptions[0].text || '').trim();
      }

      var payload = {
        first_name: ($('#firstname').val() || '').trim(),
        last_name: ($('#lastname').val() || '').trim(),
        phone: ($('#telephone').val() || '').trim(),
        address_line1: ($('#address1').val() || '').trim(),
        address_line2: ($('#address2').val() || '').trim(),
        city: ($('#city').val() || '').trim(),
        state: stateValue,
        country: countryValue,
        postal_code: ($('#post-code').val() || '').trim()
      };

      postJson('checkout-save-profile.php', payload, function (response) {
        callback(response);
      });
    }

    function placeCodOrder() {
      apiCart('checkout', {}, function (response) {
        confirmBtn.disabled = false;
        if (response && response.success) {
          showToast(response.message || 'Order placed successfully.', 'success');
          window.setTimeout(function () {
            window.location.href = authState.account_url || 'account.php';
          }, 800);
        } else {
          showToast((response && response.message) || 'Could not place order.', 'info');
        }
      });
    }

    function startRazorpayCheckout() {
      postJson('razorpay-create-order.php', {}, function (createResp) {
        if (!createResp || !createResp.success) {
          confirmBtn.disabled = false;
          showToast((createResp && createResp.message) || 'Could not start Razorpay.', 'info');
          return;
        }

        var options = {
          key: createResp.key,
          amount: createResp.amount,
          currency: createResp.currency || 'INR',
          name: 'E-commerce Checkout',
          description: createResp.description || 'Order payment',
          order_id: createResp.order_id,
          prefill: {
            name: createResp.name || '',
            email: createResp.email || '',
            contact: createResp.contact || ''
          },
          notes: {
            source: 'shop-checkout'
          },
          theme: {
            color: '#e84d1c'
          },
          handler: function (paymentResponse) {
            postJson('razorpay-verify.php', paymentResponse, function (verifyResp) {
              confirmBtn.disabled = false;
              if (verifyResp && verifyResp.success) {
                showToast(verifyResp.message || 'Payment successful.', 'success');
                window.setTimeout(function () {
                  window.location.href = authState.account_url || 'account.php';
                }, 800);
              } else {
                showToast((verifyResp && verifyResp.message) || 'Payment verification failed.', 'info');
              }
            });
          },
          modal: {
            ondismiss: function () {
              confirmBtn.disabled = false;
            }
          }
        };

        var rzp = new window.Razorpay(options);
        rzp.on('payment.failed', function (response) {
          confirmBtn.disabled = false;
          var message = 'Payment failed.';
          if (response && response.error && response.error.description) {
            message = response.error.description;
          }
          showToast(message, 'info');
        });
        rzp.open();
      });
    }

    confirmBtn.addEventListener('click', function (event) {
      event.preventDefault();

      if (!authState.logged_in) {
        showToast('Please login to place your order.', 'info');
        window.setTimeout(function () {
          var next = encodeURIComponent('shop-checkout.php');
          window.location.href = (authState.login_url || 'login.php') + '?next=' + next;
        }, 600);
        return;
      }

      if (!cartState.items.length) {
        showToast('Your cart is empty.', 'info');
        return;
      }

      confirmBtn.disabled = true;
      saveCheckoutProfile(function (saveResp) {
        if (!saveResp || !saveResp.success) {
          confirmBtn.disabled = false;
          showToast((saveResp && saveResp.message) || 'Please complete billing details.', 'info');
          return;
        }
        if (selectedPaymentMethod() === 'razorpay') {
          startRazorpayCheckout();
        } else {
          placeCodOrder();
        }
      });
    });
  }

  function init() {
    ensureUserMenu();
    ensureCartBadge();
    setStickyHeader();
    lazyImages();
    hydrateStaticProductListsFromDb();
    enhanceProductCards();
    ensureQuickViewModal();
    enhanceDetailPage();
    enhanceSearchSuggestions();
    enhanceFilters();
    enhanceMobileSidebar();
    enhanceHeroButtons();
    initBootstrapFixes();
    initTopCartToggle();
    loadDynamicCategoryNav();
    normalizeStaticCurrencyLabels();
    applyCheckoutPrefillSelects();
    handleCheckout();
    setupCouponActions();
    refreshCart();
  }

  $(document).ready(function () {
    // Safety cleanup for legacy templates that still contain this static item.
    document.querySelectorAll('.header-navigation > ul > li > a').forEach(function (a) {
      if (String(a.textContent || '').trim().toLowerCase() === 'admin theme') {
        var li = a.closest('li');
        if (li && li.parentNode) {
          li.parentNode.removeChild(li);
        }
      }
    });
    init();
  });
})(window, document, window.jQuery);

