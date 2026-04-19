import { initModule } from './moduleInit.js';
import tippy from 'tippy.js';
import GLightbox from 'glightbox';

interface ConfigMainConfig {
    isOrderByCustom?: boolean;
    maxOrderByFields?: number;
}

export function init(cfg: ConfigMainConfig): void {
    const { isOrderByCustom = false, maxOrderByFields = 3 } = cfg;

    const targets: Record<string, string> = {
        'input[name="rate"]': '#rate_anonymous',
        'input[name="allow_user_registration"]': '#email_admin_on_new_user',
        'input[name="email_admin_on_new_user"]': '#email_admin_on_new_user_filter',
    };

    Object.keys(targets).forEach(function (selector) {
        const targetEl = document.querySelector<HTMLElement>(targets[selector]);
        const sourceEl = document.querySelector<HTMLInputElement>(selector);
        if (!targetEl || !sourceEl) return;
        targetEl.style.display = sourceEl.checked ? '' : 'none';
        sourceEl.addEventListener('change', function () {
            targetEl.style.display = (this).checked ? '' : 'none';
        });
    });

    tippy('.tiptip-with-img', { maxWidth: 300, delay: 0, placement: 'top' });

    if (!isOrderByCustom) {
        const max_fields = maxOrderByFields;

        function updateFilters(): void {
            const selects = Array.from(document.querySelectorAll<HTMLSelectElement>('#order_filters select'));
            const addFilter = document.querySelector<HTMLElement>('#order_filters .addFilter');
            if (addFilter) addFilter.style.display = selects.length <= max_fields ? '' : 'none';

            const removeFilters = Array.from(document.querySelectorAll<HTMLElement>('#order_filters .removeFilter'));
            removeFilters.forEach(function (el, i) { el.style.display = i === 0 ? 'none' : ''; });

            selects.forEach(function (sel) {
                sel.querySelectorAll('option').forEach(opt => { opt.removeAttribute('disabled'); });
            });
            selects.forEach(function (sel) {
                const val = sel.value;
                selects.forEach(function (other) {
                    if (other !== sel) {
                        const opt = other.querySelector<HTMLOptionElement>('option[value="' + val + '"]');
                        if (opt) opt.setAttribute('disabled', 'disabled');
                    }
                });
            });
        }

        const orderFilters = document.getElementById('order_filters');
        if (orderFilters) {
            orderFilters.addEventListener('click', function (event) {
                const btn = (event.target as HTMLElement).closest('.removeFilter');
                if (btn) {
                    const filterSpan = (btn as HTMLElement).closest('span.filter');
                    if (filterSpan) filterSpan.remove();
                    updateFilters();
                }
            });

            orderFilters.addEventListener('change', function (event) {
                if ((event.target as HTMLElement).matches('select')) updateFilters();
            });
        }

        const addFilterBtn = document.querySelector<HTMLElement>('#order_filters .addFilter');
        if (addFilterBtn) {
            addFilterBtn.addEventListener('click', function () {
                const prevFilter = (addFilterBtn).previousElementSibling;
                if (prevFilter && prevFilter.matches('span.filter')) {
                    const clone = prevFilter.cloneNode(true) as HTMLElement;
                    addFilterBtn.parentNode?.insertBefore(clone, addFilterBtn);
                    const cloneSelect = clone.querySelector<HTMLSelectElement>('select');
                    if (cloneSelect) cloneSelect.value = '';
                }
                updateFilters();
            });
        }

        updateFilters();
    }

    GLightbox({ selector: '.themeBoxes a' });

    document.querySelectorAll<HTMLInputElement>("input[name='mail_theme']").forEach(function (el) {
        el.addEventListener('change', function () {
            document.querySelectorAll<HTMLInputElement>("input[name='mail_theme']").forEach(function (inp) {
                const ts = inp.closest<HTMLElement>('.themeSelect');
                if (ts) ts.classList.remove('themeDefault');
            });
            const myTs = (el).closest<HTMLElement>('.themeSelect');
            if (myTs) myTs.classList.add('themeDefault');
        });
    });

    document.querySelectorAll<HTMLInputElement>("input[name='email_admin_on_new_user_filter']").forEach(function (el) {
        el.addEventListener('change', function () {
            const checkedEl = document.querySelector<HTMLInputElement>("input[name='email_admin_on_new_user_filter']:checked");
            const val = checkedEl ? checkedEl.value : '';
            const groupOpts = document.getElementById('email_admin_on_new_user_filter_group_options');
            if (groupOpts) groupOpts.style.display = (val === 'group') ? '' : 'none';
        });
    });
}

initModule(init as (cfg: Record<string, unknown>) => void);
