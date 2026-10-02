/* ============================================================
 * responsive_nav.js
 * Shared mobile navigation for every Curora portal page.
 *
 * Include this file on every page that renders a sidebar:
 *     <script src="../assets/js/responsive_nav.js"></script>
 *
 * What it does (all behaviors are CSS-gated to <= 800px by the
 * "RESPONSIVE / MOBILE LAYER" section appended to each portal
 * stylesheet, so desktop layout is untouched):
 *   1. Reuses the existing <aside class="sidebar"> as an
 *      off-canvas drawer (slides in from the left).
 *   2. Injects a fixed top bar (hamburger + Curora logo +
 *      the page title) on mobile, pinned to the top of the
 *      viewport (CSS-blocked to <= 800px).
 *   3. Injects a dimmed overlay behind the drawer and a close
 *      button inside the drawer.
 *   4. Opens on hamburger tap; closes on overlay tap, close
 *      button, nav link tap, Escape, or resizing above 800px.
 *   5. Sets aria-expanded / aria-controls on the hamburger and
 *      locks body scroll while the drawer is open.
 * ============================================================ */
(function (window, document) {
  'use strict';

  function init() {
    var sidebar = document.querySelector('.sidebar');
    if (!sidebar) {
      return; // pages without a sidebar (auth, landing, etc.) are skipped
    }

    // Never run twice on the same page.
    if (document.querySelector('.mobile-topbar')) {
      return;
    }

    var body = document.body;

    /* ---------- Overlay ---------- */
    var overlay = document.createElement('div');
    overlay.id = 'nav-overlay';
    overlay.setAttribute('aria-hidden', 'true');

    /* ---------- Sticky top bar ---------- */
    var topbar = document.createElement('header');
    topbar.className = 'mobile-topbar';

    var burger = document.createElement('button');
    burger.type = 'button';
    burger.className = 'nav-burger';
    burger.setAttribute('aria-expanded', 'false');
    burger.setAttribute('aria-controls', 'mobile-nav');
    burger.setAttribute('aria-label', 'Open navigation menu');
    burger.innerHTML =
      '<span></span><span></span><span></span>';

    var brand = document.createElement('a');
    brand.className = 'mobile-topbar-logo';
    brand.setAttribute('href', window.location.pathname.split('/').pop() || 'index.php');
    brand.setAttribute('aria-label', 'Curora');

    var brandImg = sidebar.querySelector('.brand-icon img, .sidebar-brand img');
    if (brandImg && brandImg.getAttribute('src')) {
      var img = document.createElement('img');
      img.src = brandImg.getAttribute('src');
      img.alt = 'Curora';
      brand.appendChild(img);
    } else {
      var mark = document.createElement('span');
      mark.className = 'mobile-topbar-mark';
      mark.textContent = 'C';
      brand.appendChild(mark);
    }

    var title = document.createElement('span');
    title.className = 'mobile-topbar-title';
    var h1 = document.querySelector('.page-header h1') ||
             document.querySelector('main h1') ||
             document.querySelector('h1');
    if (h1 && h1.textContent.trim()) {
      title.textContent = h1.textContent.trim();
    } else {
      title.textContent = (document.title || 'Curora')
        .replace(/\s*[–—-]\s*Curora.*$/i, '')
        .replace(/\s*[–—-].*$/i, '')
        .trim() || 'Curora';
    }

    topbar.appendChild(burger);
    topbar.appendChild(brand);
    topbar.appendChild(title);

    /* ---------- Close button inside the drawer ---------- */
    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'nav-close';
    closeBtn.setAttribute('aria-label', 'Close navigation menu');
    closeBtn.innerHTML = '&times;';

    if (!sidebar.id) {
      sidebar.id = 'mobile-nav';
    }
    sidebar.insertBefore(closeBtn, sidebar.firstChild);

    /* ---------- State helpers ---------- */
    function isOpen() {
      return sidebar.classList.contains('open');
    }

    function openNav() {
      sidebar.classList.add('open');
      overlay.classList.add('open');
      document.documentElement.classList.add('nav-lock');
      burger.setAttribute('aria-expanded', 'true');
    }

    function closeNav(returnFocus) {
      sidebar.classList.remove('open');
      overlay.classList.remove('open');
      document.documentElement.classList.remove('nav-lock');
      burger.setAttribute('aria-expanded', 'false');
      if (returnFocus) {
        burger.focus();
      }
    }

    /* ---------- Wire events ---------- */
    burger.addEventListener('click', function () {
      if (isOpen()) {
        closeNav(true);
      } else {
        openNav();
      }
    });

    overlay.addEventListener('click', function () {
      closeNav(true);
    });

    closeBtn.addEventListener('click', function () {
      closeNav(true);
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isOpen()) {
        closeNav(true);
      }
    });

    // Tapping any nav link closes the drawer (navigation happens anyway).
    sidebar.addEventListener('click', function (e) {
      var t = e.target;
      var link = t && t.closest ? t.closest('a') : null;
      if (link && link.getAttribute('href') !== '#') {
        closeNav(false);
      }
    });

    // If the viewport grows past the mobile breakpoint, force close.
    window.addEventListener('resize', function () {
      if (isOpen() && window.innerWidth > 800) {
        closeNav(false);
      }
    });

    // Insert the top bar as the FIRST child of <body>, BEFORE the
    // .app / <main> content. A bar appended after <main> has its
    // natural position at the bottom of the document, where no
    // positioning (sticky or fixed) can anchor it to the top of
    // the viewport while scrolling. The overlay is position:fixed
    // with inset:0, so its DOM position does not matter.
    body.insertBefore(topbar, body.firstChild);
    body.appendChild(overlay);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);