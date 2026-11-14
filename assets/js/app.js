/**
 * Saee Life Herbal - public website scripts
 * Vanilla JS + jQuery for AJAX. No build step required.
 */
(function () {
  'use strict';

  window.SAEE = window.SAEE || {};

  /**
   * Join the install base path with a relative file path.
   * On a root install the base is '' — naively doing base + '/file.js' would produce the
   * protocol-relative "//file.js" (a request to a host literally named "file.js"), so the
   * separator is only added when there is a base to separate from.
   */
  SAEE.path = function (path) {
    var base = (SAEE.base || '').replace(/\/+$/, '');
    return base + '/' + String(path).replace(/^\/+/, '');
  };

  /* ---------------------------------------------------------------- utils */
  SAEE.post = function (url, data, onSuccess, onError) {
    data = data || {};
    if (SAEE.csrf) { data._csrf = SAEE.csrf; }
    data._ajax = 1;
    fetch(url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: new URLSearchParams(data),
      credentials: 'same-origin'
    })
      .then(function (res) { return res.json().catch(function () { return { success: false, message: 'Unexpected server response.' }; }); })
      .then(function (json) { if (json.success) { onSuccess && onSuccess(json); } else { onError && onError(json); } })
      .catch(function () { onError && onError({ success: false, message: 'Network error. Please check your connection.' }); });
  };

  SAEE.toast = function (message, type) {
    var container = document.getElementById('saeeToasts');
    if (!container) {
      container = document.createElement('div');
      container.id = 'saeeToasts';
      container.className = 'toast-container position-fixed top-0 end-0 p-3';
      container.style.zIndex = '1090';
      document.body.appendChild(container);
    }
    var colors = { success: 'bg-success', danger: 'bg-danger', warning: 'bg-warning text-dark', info: 'bg-info text-dark' };
    var el = document.createElement('div');
    el.className = 'toast align-items-center text-white border-0 ' + (colors[type] || 'bg-dark');
    el.setAttribute('role', 'alert');
    el.innerHTML = '<div class="d-flex"><div class="toast-body">' + message + '</div>' +
      '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    container.appendChild(el);
    var toast = new bootstrap.Toast(el, { delay: 3500 });
    toast.show();
    el.addEventListener('hidden.bs.toast', function () { el.remove(); });
  };

  /* --------------------------------------------------------------- header */
  function initStickyHeader() {
    var header = document.querySelector('.site-header');
    if (!header || header.dataset.sticky !== '1') { return; }
    // Below 992px the header is already sticky through CSS (mobile app shell); adding the
    // fixed/spacer behaviour there would double the offset and jump the layout. Mobile only
    // gets a compact shadow/hairline state so the sticky bar reads as "floating" above content.
    if (window.matchMedia('(max-width: 991.98px)').matches) {
      var mobileScroll = function () {
        header.classList.toggle('is-condensed', window.scrollY > 12);
      };
      window.addEventListener('scroll', mobileScroll, { passive: true });
      mobileScroll();
      return;
    }
    var spacer = document.createElement('div');
    spacer.style.display = 'none';
    header.parentNode.insertBefore(spacer, header);
    var onScroll = function () {
      if (window.scrollY > 220) {
        if (!header.classList.contains('sticky-active')) {
          spacer.style.height = header.offsetHeight + 'px';
          spacer.style.display = 'block';
          header.classList.add('sticky-active');
        }
      } else if (header.classList.contains('sticky-active')) {
        header.classList.remove('sticky-active');
        spacer.style.display = 'none';
      }
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---------------------------------------------------------------- hero */
  function initHeroSlider() {
    var slider = document.querySelector('[data-hero-slider]');
    if (!slider) { return; }
    var slides = Array.prototype.slice.call(slider.querySelectorAll('.hero-slide'));
    if (!slides.length) { return; }
    var dotsWrap = slider.querySelector('.hero-dots');
    var index = 0;
    var timer = null;
    var paused = false;

    slides.forEach(function (slide, i) {
      slide.style.transition = 'opacity .7s ease';
      slide.style.position = i === 0 ? 'relative' : 'absolute';
      slide.style.inset = i === 0 ? '' : '0';
      slide.style.opacity = i === 0 ? '1' : '0';
      slide.style.zIndex = i === 0 ? '2' : '1';
      if (i === 0) { slide.classList.add('is-active'); }
      if (dotsWrap) {
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('aria-label', 'Go to slide ' + (i + 1));
        if (i === 0) { b.classList.add('active'); }
        b.addEventListener('click', function () { go(i); });
        dotsWrap.appendChild(b);
      }
    });

    function durationFor(slide) {
      var d = parseFloat(slide.dataset.duration || '6');
      return (isNaN(d) ? 6 : d) * 1000;
    }

    function show(next) {
      var current = slides[index];
      var target = slides[next];
      current.classList.remove('is-active');
      current.style.opacity = '0';
      current.style.zIndex = '1';
      current.style.position = 'absolute';
      target.style.position = 'relative';
      target.style.zIndex = '2';
      target.style.opacity = '1';
      target.classList.add('is-active');
      // restart ken burns / animations
      var img = target.querySelector('img');
      if (img && target.dataset.imageAnim && target.dataset.imageAnim !== 'none') {
        img.style.animation = 'none';
        void img.offsetWidth;
        img.style.animation = '';
      }
      index = next;
      if (dotsWrap) {
        Array.prototype.forEach.call(dotsWrap.children, function (d, i) {
          d.classList.toggle('active', i === index);
        });
      }
    }

    function next() { show((index + 1) % slides.length); }
    function prev() { show((index - 1 + slides.length) % slides.length); }

    function schedule() {
      clearTimeout(timer);
      timer = setTimeout(function tick() {
        if (!paused) { next(); }
        timer = setTimeout(tick, durationFor(slides[index]));
      }, durationFor(slides[index]));
    }

    function go(i) { show(i); schedule(); }

    slider.querySelector('.hero-arrow.next') && slider.querySelector('.hero-arrow.next').addEventListener('click', function () { next(); schedule(); });
    slider.querySelector('.hero-arrow.prev') && slider.querySelector('.hero-arrow.prev').addEventListener('click', function () { prev(); schedule(); });
    slider.addEventListener('mouseenter', function () { paused = true; });
    slider.addEventListener('mouseleave', function () { paused = false; });
    // touch swipe
    var startX = null;
    slider.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
    slider.addEventListener('touchend', function (e) {
      if (startX === null) { return; }
      var delta = e.changedTouches[0].clientX - startX;
      if (Math.abs(delta) > 50) { delta > 0 ? prev() : next(); schedule(); }
      startX = null;
    });
    if (slides.length > 1) { schedule(); }
  }

  /* ------------------------------------------------------------ counters */
  function initCounters() {
    var counters = document.querySelectorAll('[data-count-to]');
    if (!counters.length) { return; }
    var animate = function (el) {
      var target = parseFloat(el.dataset.countTo || '0');
      var suffix = el.dataset.suffix || '';
      var prefix = el.dataset.prefix || '';
      var duration = 1500;
      var start = performance.now();
      var decimals = (el.dataset.decimals ? parseInt(el.dataset.decimals, 10) : 0);
      function frame(now) {
        var progress = Math.min(1, (now - start) / duration);
        var eased = 1 - Math.pow(1 - progress, 3);
        var value = target * eased;
        el.textContent = prefix + value.toLocaleString('en-IN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + suffix;
        if (progress < 1) { requestAnimationFrame(frame); }
      }
      requestAnimationFrame(frame);
    };
    if (!('IntersectionObserver' in window)) { counters.forEach(animate); return; }
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) { animate(entry.target); observer.unobserve(entry.target); }
      });
    }, { threshold: 0.3 });
    counters.forEach(function (c) { observer.observe(c); });
  }

  /* -------------------------------------------------------------- reveal */
  function initReveal() {
    var items = document.querySelectorAll('.reveal');
    if (!items.length || !('IntersectionObserver' in window)) {
      items.forEach(function (i) { i.classList.add('in-view'); });
      return;
    }
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in-view');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    items.forEach(function (i) { observer.observe(i); });
  }

  /* ---------------------------------------------------------------- cart */
  function updateCartCount(count) {
    document.querySelectorAll('[data-cart-count]').forEach(function (el) { el.textContent = count; });
  }

  function initAddToCart() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-add-to-cart]');
      if (!btn) { return; }
      e.preventDefault();
      var productId = btn.dataset.addToCart;
      var qtyInput = document.querySelector('[data-qty-input]');
      var qty = btn.dataset.qty || (qtyInput ? qtyInput.value : 1);
      // `disabled` would fade the button (Bootstrap's :disabled opacity) — block repeat
      // clicks with a class instead so the confirmation stays full-contrast.
      btn.classList.add('is-busy');
      var original = btn.innerHTML;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>';
      SAEE.post(SAEE.routes.cartAdd, { product_id: productId, quantity: qty }, function (res) {
        SAEE.toast(res.message, 'success');
        if (res.data && res.data.count !== undefined) { updateCartCount(res.data.count); }
        // keep the button's own markup shape (icon + label) and just show a confirmed state
        var label = btn.querySelector('[data-btn-label]');
        if (label) {
          label.textContent = 'Added to cart';
        } else {
          btn.innerHTML = '<i class="fa-solid fa-check me-2" aria-hidden="true"></i>Added to cart';
        }
        btn.classList.add('is-added');
        setTimeout(function () {
          btn.innerHTML = original;
          btn.classList.remove('is-added', 'is-busy');
        }, 1600);
      }, function (err) {
        SAEE.toast(err.message || 'Could not add to cart.', 'danger');
        btn.innerHTML = original;
        btn.classList.remove('is-busy');
      });
    });
  }

  function initWishlist() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-wishlist]');
      if (!btn) { return; }
      e.preventDefault();
      SAEE.post(SAEE.routes.wishlistToggle, { product_id: btn.dataset.wishlist }, function (res) {
        btn.classList.toggle('active', !!res.data.added);
        btn.classList.toggle('is-active', !!res.data.added);
        btn.setAttribute('aria-pressed', res.data.added ? 'true' : 'false');
        var icon = btn.querySelector('i');
        if (icon) {
          icon.className = res.data.added ? 'fa-solid fa-heart' : 'fa-regular fa-heart';
        }
        var label = btn.querySelector('[data-wishlist-label]');
        if (label) { label.textContent = res.data.added ? 'Saved' : 'Wishlist'; }
        SAEE.toast(res.message, 'success');
      }, function (err) { SAEE.toast(err.message, 'danger'); });
    });
  }

  function initCartQty() {
    document.addEventListener('change', function (e) {
      var input = e.target.closest('[data-cart-qty]');
      if (!input) { return; }
      SAEE.post(SAEE.routes.cartUpdate, { item_id: input.dataset.cartQty, quantity: input.value }, function (res) {
        window.location.reload();
      }, function (err) { SAEE.toast(err.message, 'danger'); });
    });
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-cart-remove]');
      if (!btn) { return; }
      e.preventDefault();
      SAEE.post(SAEE.routes.cartRemove, { item_id: btn.dataset.cartRemove }, function () { window.location.reload(); }, function (err) { SAEE.toast(err.message, 'danger'); });
    });
  }

  /* -------------------------------------------------------- sponsor lookup */
  function initSponsorLookup() {
    var input = document.querySelector('[data-sponsor-input]');
    if (!input) { return; }
    var feedback = document.querySelector('[data-sponsor-feedback]');
    var timer = null;
    var run = function () {
      var value = input.value.trim();
      SAEE.post(SAEE.routes.sponsorLookup, { sponsor: value }, function (res) {
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
        if (feedback) {
          feedback.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i>' + res.data.name + ' <span class="text-muted">(' + res.data.member_code + (res.data.company ? ' · Company' : '') + ')</span>';
          feedback.className = 'form-text text-success';
        }
      }, function (err) {
        input.classList.remove('is-valid');
        input.classList.add('is-invalid');
        if (feedback) {
          feedback.innerHTML = '<i class="fa-solid fa-circle-exclamation me-1"></i>' + (err.message || 'Invalid sponsor ID');
          feedback.className = 'form-text text-danger';
        }
      });
    };
    input.addEventListener('input', function () {
      clearTimeout(timer);
      var v = input.value.trim();
      if (v.length < 3) { feedback && (feedback.innerHTML = ''); input.classList.remove('is-valid', 'is-invalid'); return; }
      timer = setTimeout(run, 420);
    });
    if (input.value.trim().length >= 3) { run(); }
  }

  /* --------------------------------------------------------------- forms */
  function initAjaxForms() {
    document.addEventListener('submit', function (e) {
      var form = e.target.closest('form[data-ajax-form]');
      if (!form) { return; }
      e.preventDefault();
      var button = form.querySelector('[type=submit]');
      var original = button ? button.innerHTML : '';
      if (button) { button.disabled = true; button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending…'; }
      var formData = new FormData(form);
      fetch(form.action, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Unexpected response.' }; }); })
        .then(function (json) {
          if (json.success) {
            SAEE.toast(json.message || 'Submitted successfully.', 'success');
            form.reset();
            form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
            if (json.data && json.data.redirect) { setTimeout(function () { window.location.href = json.data.redirect; }, 700); }
            if (form.dataset.resetTarget) {
              var target = document.querySelector(form.dataset.resetTarget);
              if (target) { target.innerHTML = '<div class="alert alert-success mb-0">' + (json.message || 'Thank you!') + '</div>'; }
            }
          } else {
            SAEE.toast(json.message || 'Please check the form.', 'danger');
            if (json.errors) {
              Object.keys(json.errors).forEach(function (field) {
                var el = form.querySelector('[name="' + field + '"]');
                if (el) {
                  el.classList.add('is-invalid');
                  var msg = el.parentNode.querySelector('.invalid-feedback');
                  if (!msg) { msg = document.createElement('div'); msg.className = 'invalid-feedback'; el.parentNode.appendChild(msg); }
                  msg.textContent = json.errors[field][0];
                }
              });
            }
          }
        })
        .catch(function () { SAEE.toast('Network error, please retry.', 'danger'); })
        .finally(function () { if (button) { button.disabled = false; button.innerHTML = original; } });
    });
  }

  /* ------------------------------------------------------------- gallery */
  function initProductGallery() {
    var main = document.querySelector('[data-gallery-main]');
    if (!main) { return; }
    var thumbs = Array.prototype.slice.call(document.querySelectorAll('[data-gallery-thumb]'));
    var stage = document.querySelector('[data-gallery-stage]');
    var counter = document.querySelector('[data-gallery-counter]');
    var strip = document.querySelector('[data-gallery-thumbs]');
    var current = 0;

    function show(index) {
      if (!thumbs.length) { return; }
      current = (index + thumbs.length) % thumbs.length;
      var thumb = thumbs[current];
      main.style.opacity = '0';
      var next = new Image();
      next.onload = next.onerror = function () {
        main.src = thumb.dataset.galleryThumb;
        main.style.opacity = '1';
      };
      next.src = thumb.dataset.galleryThumb;
      thumbs.forEach(function (t, i) {
        t.classList.toggle('active', i === current);
        t.setAttribute('aria-selected', i === current ? 'true' : 'false');
      });
      if (counter) { counter.textContent = (current + 1) + ' / ' + thumbs.length; }
      // keep the active thumb visible inside the strip
      if (strip) {
        var t = thumbs[current];
        var left = t.offsetLeft - strip.offsetLeft;
        if (left < strip.scrollLeft || left + t.offsetWidth > strip.scrollLeft + strip.clientWidth) {
          strip.scrollTo({ left: left - strip.clientWidth / 2 + t.offsetWidth / 2, behavior: 'smooth' });
        }
      }
    }

    thumbs.forEach(function (thumb, i) {
      thumb.addEventListener('click', function () { show(i); });
    });

    var prevImg = document.querySelector('[data-gallery-prev]');
    var nextImg = document.querySelector('[data-gallery-next]');
    if (prevImg) { prevImg.addEventListener('click', function () { show(current - 1); }); }
    if (nextImg) { nextImg.addEventListener('click', function () { show(current + 1); }); }
    if (stage) {
      stage.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight') { show(current + 1); }
        if (e.key === 'ArrowLeft') { show(current - 1); }
      });
      // finger swipe over the main image on touch devices
      var sx = null, sy = null;
      stage.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
      stage.addEventListener('touchend', function (e) {
        if (sx === null) { return; }
        var dx = e.changedTouches[0].clientX - sx;
        var dy = e.changedTouches[0].clientY - sy;
        if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) { show(dx < 0 ? current + 1 : current - 1); }
        sx = sy = null;
      }, { passive: true });
    }

    /* Thumbnail strip -------------------------------------------------------
       Auto-scrolls gently, pauses on interaction, and accepts wheel, mouse drag
       and touch scrolling. The arrow buttons page it left/right. */
    if (strip) {
      var scrolling = null;
      function step() { return Math.max(80, Math.round(strip.clientWidth * 0.7)); }
      function page(dir) {
        strip.scrollBy({ left: dir * step(), behavior: 'smooth' });
        pauseAuto();
      }
      var prevBtn = document.querySelector('[data-thumb-prev]');
      var nextBtn = document.querySelector('[data-thumb-next]');
      if (prevBtn) { prevBtn.addEventListener('click', function () { page(-1); }); }
      if (nextBtn) { nextBtn.addEventListener('click', function () { page(1); }); }

      var wrap = document.querySelector('[data-gallery-wrap]');
      function syncArrows() {
        if (!prevBtn || !nextBtn) { return; }
        var max = strip.scrollWidth - strip.clientWidth - 2;
        var fits = max <= 0;
        if (wrap) { wrap.classList.toggle('is-scrollable', !fits); }
        prevBtn.disabled = strip.scrollLeft <= 2;
        nextBtn.disabled = strip.scrollLeft >= max;
      }
      strip.addEventListener('scroll', syncArrows, { passive: true });
      window.addEventListener('resize', syncArrows);

      // vertical wheel / trackpad gestures scroll the strip horizontally
      strip.addEventListener('wheel', function (e) {
        var delta = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : e.deltaY;
        if (!delta) { return; }
        var max = strip.scrollWidth - strip.clientWidth;
        if ((strip.scrollLeft <= 0 && delta < 0) || (strip.scrollLeft >= max && delta > 0)) { return; }
        e.preventDefault();
        strip.scrollLeft += delta;
        pauseAuto();
      }, { passive: false });

      // mouse drag (pointer events cover pen + touch as well)
      var dragging = false, startX = 0, startScroll = 0, moved = 0;
      strip.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'touch') { return; } // native touch scrolling is better
        dragging = true; moved = 0; startX = e.clientX; startScroll = strip.scrollLeft;
        strip.classList.add('is-dragging');
        pauseAuto();
      });
      strip.addEventListener('pointermove', function (e) {
        if (!dragging) { return; }
        var dx = e.clientX - startX;
        moved = Math.abs(dx);
        strip.scrollLeft = startScroll - dx;
        if (moved > 6) { e.preventDefault(); }
      });
      function endDrag() {
        if (!dragging) { return; }
        dragging = false;
        strip.classList.remove('is-dragging');
        // swallow the click that follows a real drag so the image does not change
        if (moved > 8) {
          strip.addEventListener('click', function stop(e) { e.stopPropagation(); e.preventDefault(); strip.removeEventListener('click', stop, true); }, true);
        }
      }
      strip.addEventListener('pointerup', endDrag);
      strip.addEventListener('pointerleave', endDrag);
      strip.addEventListener('pointercancel', endDrag);
      strip.addEventListener('mouseenter', pauseAuto);
      strip.addEventListener('touchstart', pauseAuto, { passive: true });

      var autoTimer = null, autoDir = 1, pausedUntil = 0;
      function pauseAuto() { pausedUntil = Date.now() + 4500; }
      function startAuto() {
        window.clearInterval(autoTimer);
        autoTimer = window.setInterval(function () {
          if (document.hidden || Date.now() < pausedUntil) { return; }
          var max = strip.scrollWidth - strip.clientWidth;
          if (max <= 0) { return; }
          if (strip.scrollLeft >= max - 2) { autoDir = -1; }
          if (strip.scrollLeft <= 2) { autoDir = 1; }
          strip.scrollLeft += autoDir * 1; // 1px per tick = a slow, non-distracting drift
        }, 40);
      }
      startAuto();
      syncArrows();
    }
  }

  /* ------------------------------------------------------ qty + price calc */
  function initQtyInputs() {
    document.querySelectorAll('[data-qty-step]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = document.querySelector('[data-qty-input]');
        if (!input) { return; }
        var value = parseInt(input.value, 10) || 1;
        value += btn.dataset.qtyStep === 'up' ? 1 : -1;
        if (value < 1) { value = 1; }
        input.value = value;
      });
    });
  }

  /* ------------------------------------------------------------ copy link */
  function initCopy() {
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-copy]');
      if (!trigger) { return; }
      e.preventDefault();
      var text = trigger.dataset.copy;
      var done = function () { SAEE.toast('Copied to clipboard.', 'success'); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
      } else { fallbackCopy(text, done); }
    });
  }

  function fallbackCopy(text, done) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (err) { SAEE.toast('Please copy manually.', 'warning'); }
    ta.remove();
  }

  /* ------------------------------------------------------------ back top */
  function initBackToTop() {
    var btn = document.querySelector('.back-to-top');
    if (!btn) { return; }
    window.addEventListener('scroll', function () {
      btn.classList.toggle('show', window.scrollY > 500);
    }, { passive: true });
    btn.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
  }

  /* -------------------------------------------------- mobile app shell */
  function initMobileShell() {
    var tabbar = document.querySelector('.app-tabbar');
    if (!tabbar) { return; }

    // Reflect the current URL in the tab bar even when the server-side active class is
    // not applicable (query strings, pagination, product detail pages).
    var path = location.pathname.replace(/\/+$/, '');
    var links = Array.prototype.slice.call(tabbar.querySelectorAll('a'));
    links.forEach(function (a) {
      var href = a.getAttribute('href') || '';
      try {
        var target = new URL(href, location.href).pathname.replace(/\/+$/, '');
        var isHome = target === '' || target === new URL(SAEE.base + '/').pathname.replace(/\/+$/, '');
        if (isHome ? (path === '' || path === new URL(SAEE.base + '/').pathname.replace(/\/+$/, '')) : (path === target || path.indexOf(target + '/') === 0)) {
          links.forEach(function (other) { other.classList.remove('active'); });
          a.classList.add('active');
        }
      } catch (e) { /* ignore malformed hrefs */ }
    });

    // App-like behaviour: hide the tab bar while scrolling down, reveal on scroll up.
    var lastY = window.scrollY;
    var hidden = false;
    var atBottom = function () {
      return window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 90;
    };
    window.addEventListener('scroll', function () {
      var y = window.scrollY;
      if (atBottom()) {
        // Never hide the navigation at the very end of the page — that is where people
        // are most likely to want the next tap.
        if (hidden) { tabbar.style.transform = 'translateY(0)'; hidden = false; }
        lastY = y;
        return;
      }
      if (y > lastY + 8 && y > 140 && !hidden) {
        tabbar.style.transform = 'translateY(110%)';
        hidden = true;
      } else if ((y < lastY - 8 || y <= 140) && hidden) {
        tabbar.style.transform = 'translateY(0)';
        hidden = false;
      }
      lastY = y;
    }, { passive: true });

    // Keep the document class in sync so the safe-area padding is always applied.
    document.body.classList.add('has-tabbar');

    // Close the offcanvas drawer after a navigation tap (instant app feel).
    document.querySelectorAll('.offcanvas-mobile a[href]').forEach(function (link) {
      link.addEventListener('click', function () {
        var instance = window.bootstrap && bootstrap.Offcanvas.getInstance(document.getElementById('mobileNav'));
        if (instance) { instance.hide(); }
      });
    });

    // Light haptic feedback where supported.
    tabbar.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () { if (navigator.vibrate) { navigator.vibrate(8); } });
    });
  }

  /* ------------------------------------------------------- carousels */
  /**
   * Generic horizontal carousel used by the home page categories and the testimonials.
   * Tracks the visible card width so it works with any number of cards per breakpoint:
   * phones swipe (native momentum + snap), tablets/desktop use the arrow buttons and dots.
   */
  function initCarousel(opts) {
    var root = document.querySelector(opts.root);
    if (!root) { return; }
    var track = root.querySelector(opts.track);
    var slides = Array.prototype.slice.call(root.querySelectorAll(opts.slide));
    if (!track || slides.length < 2) { return; }

    var dotsWrap = root.querySelector(opts.dots);
    var prevBtn = root.querySelector(opts.prev);
    var nextBtn = root.querySelector(opts.next);
    var controls = root.querySelector(opts.controls);
    var index = 0;
    var built = false;

    function perView() { return opts.perView(window.innerWidth); }
    function last() { return Math.max(0, slides.length - perView()); }
    function single() { return perView() === 1; }

    function metrics() {
      var w = slides[0].getBoundingClientRect().width;
      var cs = getComputedStyle(track);
      var gap = parseFloat(cs.columnGap || cs.gap || '0') || 0;
      return { step: w + gap };
    }

    function buildDots() {
      if (!dotsWrap || built) { return; }
      built = true;
      var count = last() + 1;
      for (var i = 0; i < count; i++) {
        (function (n) {
          var b = document.createElement('button');
          b.type = 'button';
          b.setAttribute('role', 'tab');
          b.setAttribute('aria-label', (opts.label || 'Item') + ' ' + (n + 1));
          b.addEventListener('click', function () { go(n); });
          dotsWrap.appendChild(b);
        })(i);
      }
    }

    function render() {
      index = Math.min(index, last());
      if (single()) {
        track.style.transform = '';
        root.scrollLeft = index * metrics().step;
      } else {
        track.style.transform = 'translate3d(' + (-index * metrics().step) + 'px,0,0)';
      }
      slides.forEach(function (s, i) {
        s.setAttribute('aria-hidden', i >= index && i < index + perView() ? 'false' : 'true');
      });
      if (dotsWrap) {
        Array.prototype.forEach.call(dotsWrap.children, function (dot, i) {
          var on = i === index;
          dot.classList.toggle('active', on);
          dot.setAttribute('aria-selected', on ? 'true' : 'false');
        });
      }
      var needed = last() > 0;
      if (controls) { controls.style.display = needed ? '' : 'none'; }
      if (prevBtn) { prevBtn.disabled = index <= 0; }
      if (nextBtn) { nextBtn.disabled = index >= last(); }
    }

    function go(i) {
      index = i < 0 ? last() : (i > last() ? 0 : i);
      render();
    }

    buildDots();
    if (prevBtn) { prevBtn.addEventListener('click', function () { go(index - 1); }); }
    if (nextBtn) { nextBtn.addEventListener('click', function () { go(index + 1); }); }
    root.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { go(index + 1); e.preventDefault(); }
      if (e.key === 'ArrowLeft') { go(index - 1); e.preventDefault(); }
    });

    // Touch: native scroll + scroll-snap. Works with or without a snap support.
    root.style.overflowX = 'auto';
    root.style.scrollSnapType = 'x mandatory';
    root.classList.add('is-swipeable');
    slides.forEach(function (s) { s.style.scrollSnapAlign = 'start'; });
    var syncTimer = null;
    root.addEventListener('scroll', function () {
      window.clearTimeout(syncTimer);
      syncTimer = window.setTimeout(function () {
        if (!single()) { return; }
        var step = metrics().step;
        if (step <= 0) { return; }
        var nth = Math.round(root.scrollLeft / step);
        if (nth !== index && nth >= 0 && nth <= last()) { index = nth; render(); }
      }, 90);
    }, { passive: true });

    // Mouse drag / finger swipe fallback (for browsers without scroll-snap).
    var startX = null, startY = null;
    root.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; startY = e.touches[0].clientY; }, { passive: true });
    root.addEventListener('touchend', function (e) {
      if (startX === null) { return; }
      var dx = e.changedTouches[0].clientX - startX;
      var dy = e.changedTouches[0].clientY - startY;
      if (single() && Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) { go(dx < 0 ? index + 1 : index - 1); }
      startX = startY = null;
    }, { passive: true });

    var resizeTimer = null;
    window.addEventListener('resize', function () {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(function () {
        // Dots depend on how many cards fit per view, so rebuild them on breakpoint changes.
        if (dotsWrap) { dotsWrap.innerHTML = ''; built = false; buildDots(); }
        render();
      }, 150);
    });
    render();
  }

  function initTestimonialCarousel() {
    initCarousel({
      root: '[data-testimonial-carousel]',
      track: '[data-track]',
      slide: '.testimonial-slide',
      dots: '[data-t-dots]',
      prev: '[data-t-prev]',
      next: '[data-t-next]',
      controls: '.t-controls',
      label: 'Testimonial',
      perView: function (w) { return w < 768 ? 1 : (w < 992 ? 2 : 3); }
    });
  }

  function initCategoryCarousel() {
    initCarousel({
      root: '[data-cat-carousel]',
      track: '[data-cat-track]',
      slide: '.cat-slide',
      dots: '[data-cat-dots]',
      prev: '[data-cat-prev]',
      next: '[data-cat-next]',
      controls: '.cat-controls',
      label: 'Category',
      perView: function (w) { return w < 768 ? 1 : (w < 992 ? 2 : 3); }
    });
  }

  function initFeaturedCarousel() {
    initCarousel({
      root: '[data-feat-carousel]',
      track: '[data-feat-track]',
      slide: '.feat-slide',
      dots: '[data-feat-dots]',
      prev: '[data-feat-prev]',
      next: '[data-feat-next]',
      controls: '.feat-controls',
      label: 'Product',
      perView: function (w) { return w < 576 ? 1 : (w < 992 ? 2 : (w < 1200 ? 3 : 4)); }
    });
  }

  /* ---------------------------------------------------------------- PWA */  /* ---------------------------------------------------------------- PWA */
  function initPWA() {
    if (!('serviceWorker' in navigator)) { return; }
    // Explicit opt-out (the admin panel sets it): those pages are never cached, so a
    // worker there buys nothing and only risks taking control of an authenticated area.
    if (SAEE.pwa === false) { return; }
    // Without an injected base the install path is unknown. Guessing would build a
    // root-absolute /service-worker.js, which 404s on any sub-directory install —
    // so say why offline support is off instead of firing a request that cannot work.
    if (typeof SAEE.base !== 'string') {
      if (window.console && console.warn) {
        console.warn('Offline support disabled: window.SAEE.base was not set by this page.');
      }
      return;
    }
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(SAEE.path('service-worker.js'), { scope: SAEE.path('') }).then(function (reg) {
        reg.addEventListener('updatefound', function () {
          var worker = reg.installing;
          worker && worker.addEventListener('statechange', function () {
            if (worker.state === 'installed' && navigator.serviceWorker.controller) {
              showUpdateBanner(reg);
            }
          });
        });
      }).catch(function () { /* offline support is optional */ });
    });
  }

  function showUpdateBanner(registration) {
    var banner = document.createElement('div');
    banner.className = 'position-fixed bottom-0 start-50 translate-middle-x mb-4 p-3 bg-dark text-white rounded-4 shadow-lg d-flex align-items-center gap-3';
    banner.style.zIndex = '1080';
    banner.innerHTML = '<span><i class="fa-solid fa-arrows-rotate me-2"></i>A new version is available.</span>' +
      '<button class="btn btn-sm btn-accent">Refresh</button>';
    banner.querySelector('button').addEventListener('click', function () {
      registration.waiting && registration.waiting.postMessage({ type: 'SKIP_WAITING' });
      window.location.reload();
    });
    document.body.appendChild(banner);
  }

  function initInstallPrompt() {
    var deferred = null;
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      deferred = e;
      var btn = document.querySelector('[data-pwa-install]');
      if (btn) {
        btn.classList.remove('d-none');
        btn.addEventListener('click', function () {
          if (!deferred) { return; }
          deferred.prompt();
          deferred.userChoice.then(function () { deferred = null; btn.classList.add('d-none'); });
        });
      }
    });
  }

  /* --------------------------------------------------------------- boot */
  document.addEventListener('DOMContentLoaded', function () {
    initStickyHeader();
    initHeroSlider();
    initCounters();
    initReveal();
    initAddToCart();
    initWishlist();
    initCartQty();
    initSponsorLookup();
    initAjaxForms();
    initProductGallery();
    initQtyInputs();
    initCopy();
    initBackToTop();
    initMobileShell();
    initTestimonialCarousel();
    initCategoryCarousel();
    initFeaturedCarousel();
    initPWA();
    initInstallPrompt();

    // Bootstrap tooltips
    if (window.bootstrap && bootstrap.Tooltip) {
      document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) { new bootstrap.Tooltip(el); });
    }

    // auto dismiss server flash messages
    document.querySelectorAll('[data-auto-dismiss]').forEach(function (el) {
      setTimeout(function () { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 420); }, 6000);
    });

    // track page view (analytics, session scoped)
    if (SAEE.routes && SAEE.routes.track) {
      fetch(SAEE.routes.track, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' }, body: 'url=' + encodeURIComponent(location.pathname), credentials: 'same-origin' }).catch(function () {});
    }
  });
})();
