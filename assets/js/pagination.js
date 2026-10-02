/* ============================================================
 * pagination.js
 * Reusable client-side pagination bar for the Curora portals
 * (staff, doctor, etc.).
 *
 * Markup it drives (see .pagination-bar in the portal CSS):
 *   <div class="pagination-bar" data-pagination>
 *     <div class="pagination-row">
 *       <button type="button" class="pagination-btn" data-pagination-prev>Previous</button>
 *       <span class="pagination-label" data-pagination-label>Page 1 of 1</span>
 *       <button type="button" class="pagination-btn" data-pagination-next>Next</button>
 *     </div>
 *   </div>
 *
 * Usage:
 *   window.checkinPager = attachPagination({
 *     bar:   document.querySelector('[data-pagination]'),
 *     items: function () { return document.querySelectorAll('.some-row'); },
 *     perPage: 8,
 *     isItemVisible: function (item) { return true; /* e.g. search filter *\/ },
 *     onPageChange: function (page, totalPages, visibleCount) { /* optional *\/ }
 *   });
 *   window.checkinPager.refresh();   // re-run after data/filter changes
 *   window.checkinPager.goToPage(2);
 * ============================================================ */
(function (window, document) {
  'use strict';

  function attachPagination(config) {
    var bar = config && config.bar;

    if (!bar) {
      return null;
    }

    var prevBtn = bar.querySelector('[data-pagination-prev]');
    var nextBtn = bar.querySelector('[data-pagination-next]');
    var labelEl = bar.querySelector('[data-pagination-label]');

    var perPage = Math.max(1, parseInt(config.perPage, 10) || 8);
    var currentPage = 1;

    function getItems() {
      return Array.prototype.slice.call(config.items());
    }

    function getVisibleItems() {
      var all = getItems();

      if (typeof config.isItemVisible === 'function') {
        return all.filter(config.isItemVisible);
      }

      return all;
    }

    function getTotalPages() {
      return Math.max(1, Math.ceil(getVisibleItems().length / perPage));
    }

    function render() {
      var total = getTotalPages();

      // Clamp the current page so a filter/refresh never leaves us
      // stranded on an out-of-range page.
      if (currentPage > total) { currentPage = total; }
      if (currentPage < 1)     { currentPage = 1; }

      var start = (currentPage - 1) * perPage;
      var end   = start + perPage;

      // Hide every item first, then reveal the slice for the current
      // page. This keeps stale visibility from a previous page (or a
      // removed filter) from leaving rows visible.
      var all     = getItems();
      var visible = (typeof config.isItemVisible === 'function')
        ? all.filter(config.isItemVisible)
        : all;

      all.forEach(function (item) {
        item.style.display = 'none';
      });

      visible
        .slice(start, end)
        .forEach(function (item) {
          item.style.display = '';
        });

      if (prevBtn) { prevBtn.disabled = currentPage <= 1; }
      if (nextBtn) { nextBtn.disabled = currentPage >= total; }

      if (labelEl) {
        labelEl.textContent =
          'Page ' + currentPage + ' of ' + total;
      }

      if (typeof config.onPageChange === 'function') {
        config.onPageChange(currentPage, total, visible.length);
      }
    }

    function goToPage(page) {
      currentPage = page;
      render();
    }

    if (prevBtn) {
      prevBtn.addEventListener('click', function () {
        goToPage(currentPage - 1);
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', function () {
        goToPage(currentPage + 1);
      });
    }

    render();

    return {
      goToPage: goToPage,
      refresh: render
    };
  }

  window.attachPagination = attachPagination;
  window.CuroraPagination = { attachPagination: attachPagination };

})(window, document);