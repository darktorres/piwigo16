import { initModule } from './moduleInit.js';

export function init(cfg) {
  const { isOrderByCustom, maxOrderByFields } = cfg;

  const targets = {
    'input[name="rate"]': '#rate_anonymous',
    'input[name="allow_user_registration"]': '#email_admin_on_new_user',
    'input[name="email_admin_on_new_user"]': '#email_admin_on_new_user_filter'
  };

  Object.keys(targets).forEach(function(selector) {
    const target = targets[selector];
    const targetEl = document.querySelector(target);
    const sourceEl = document.querySelector(selector);
    if (!targetEl || !sourceEl) return;
    targetEl.style.display = sourceEl.checked ? '' : 'none';
    (function(targetEl) {
      sourceEl.addEventListener('change', function() {
        targetEl.style.display = this.checked ? '' : 'none';
      });
    })(targetEl);
  });

  tippy('.tiptip-with-img', { maxWidth: 300, delay: 0, placement: 'top' });

  if (!isOrderByCustom) {
    (function() {
      const max_fields = maxOrderByFields;

      function updateFilters() {
        const selects = Array.from(document.querySelectorAll('#order_filters select'));
        const addFilter = document.querySelector('#order_filters .addFilter');
        if (addFilter) addFilter.style.display = selects.length <= max_fields ? '' : 'none';

        const removeFilters = Array.from(document.querySelectorAll('#order_filters .removeFilter'));
        removeFilters.forEach(function(el, i) { el.style.display = i === 0 ? 'none' : ''; });

        selects.forEach(function(sel) {
          sel.querySelectorAll('option').forEach(function(opt) { opt.removeAttribute('disabled'); });
        });
        selects.forEach(function(sel) {
          const val = sel.value;
          selects.forEach(function(other) {
            if (other !== sel) {
              const opt = other.querySelector('option[value="' + val + '"]');
              if (opt) opt.setAttribute('disabled', 'disabled');
            }
          });
        });
      }

      const orderFilters = document.getElementById('order_filters');
      if (orderFilters) {
        orderFilters.addEventListener('click', function(event) {
          const btn = event.target.closest('.removeFilter');
          if (btn) {
            const filterSpan = btn.closest('span.filter');
            if (filterSpan) filterSpan.remove();
            updateFilters();
          }
        });

        orderFilters.addEventListener('change', function(event) {
          if (event.target.matches('select')) updateFilters();
        });
      }

      const addFilterBtn = document.querySelector('#order_filters .addFilter');
      if (addFilterBtn) {
        addFilterBtn.addEventListener('click', function() {
          const prevFilter = this.previousElementSibling;
          if (prevFilter && prevFilter.matches('span.filter')) {
            const clone = prevFilter.cloneNode(true);
            this.parentNode.insertBefore(clone, this);
            const cloneSelect = clone.querySelector('select');
            if (cloneSelect) cloneSelect.value = '';
          }
          updateFilters();
        });
      }

      updateFilters();
    }());
  }

  GLightbox({ selector: '.themeBoxes a' });

  document.querySelectorAll("input[name='mail_theme']").forEach(function(el) {
    el.addEventListener('change', function() {
      document.querySelectorAll("input[name='mail_theme']").forEach(function(inp) {
        const ts = inp.closest(".themeSelect");
        if (ts) ts.classList.remove("themeDefault");
      });
      const myTs = this.closest(".themeSelect");
      if (myTs) myTs.classList.add("themeDefault");
    });
  });

  document.querySelectorAll("input[name='email_admin_on_new_user_filter']").forEach(function(el) {
    el.addEventListener('change', function() {
      const checkedEl = document.querySelector("input[name='email_admin_on_new_user_filter']:checked");
      const val = checkedEl ? checkedEl.value : '';
      const groupOpts = document.getElementById('email_admin_on_new_user_filter_group_options');
      if (groupOpts) groupOpts.style.display = (val === 'group') ? '' : 'none';
    });
  });
}

initModule(init);
