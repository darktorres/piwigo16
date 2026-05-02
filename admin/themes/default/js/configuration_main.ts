import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.css';
import { getPageData } from './page-data';

interface ConfigurationMainPageData {
    order_by_is_custom: boolean;
    order_by_options_count: number;
}

const pageData = getPageData<ConfigurationMainPageData>();

/*---- Toggle related-input visibility based on a master checkbox ----*/
const toggleTargets: Record<string, string> = {
    'input[name="rate"]':                       '#rate_anonymous',
    'input[name="allow_user_registration"]':    '#email_admin_on_new_user',
    'input[name="email_admin_on_new_user"]':    '#email_admin_on_new_user_filter',
};

for (const selector in toggleTargets) {
    const target = toggleTargets[selector];
    const targetEl = document.querySelector<HTMLElement>(target);
    const selectorEl = document.querySelector<HTMLInputElement>(selector);
    if (targetEl && selectorEl) {
        targetEl.style.display = selectorEl.checked ? '' : 'none';
        selectorEl.addEventListener('change', () => {
            targetEl.style.display = selectorEl.checked ? '' : 'none';
        });
    }
}

/*---- Order-by filters dynamic add/remove ----*/
if (!pageData.order_by_is_custom) {
    const max_fields = Math.ceil(pageData.order_by_options_count / 2);

    function updateFilters(): void {
        const selects = Array.from(document.querySelectorAll<HTMLSelectElement>('#order_filters select'));

        const addFilter = document.querySelector<HTMLElement>('#order_filters .addFilter');
        if (addFilter) {
            addFilter.style.display = selects.length <= max_fields ? '' : 'none';
        }

        document.querySelectorAll<HTMLElement>('#order_filters .removeFilter').forEach((rf, idx) => {
            rf.style.display = idx === 0 ? 'none' : '';
        });

        selects.forEach((sel) => {
            Array.from(sel.options).forEach((opt) => { opt.disabled = false; });
        });
        selects.forEach((sel) => {
            const val = sel.value;
            selects.filter((s) => s !== sel).forEach((other) => {
                const opt = other.querySelector<HTMLOptionElement>(`option[value="${val}"]`);
                if (opt) opt.setAttribute('disabled', 'disabled');
            });
        });
    }

    const orderFilters = document.getElementById('order_filters');
    orderFilters?.addEventListener('click', (e) => {
        const btn = (e.target as HTMLElement).closest<HTMLElement>('.removeFilter');
        if (!btn) return;
        const filterSpan = btn.closest('span.filter');
        if (filterSpan) filterSpan.remove();
        updateFilters();
    });

    orderFilters?.addEventListener('change', (e) => {
        if ((e.target as HTMLElement).tagName === 'SELECT') updateFilters();
    });

    const addFilterBtn = document.querySelector<HTMLElement>('#order_filters .addFilter');
    if (addFilterBtn) {
        addFilterBtn.addEventListener('click', function(this: HTMLElement) {
            const prevFilter = this.previousElementSibling;
            if (prevFilter && prevFilter.matches('span.filter') && this.parentNode) {
                const clone = prevFilter.cloneNode(true) as HTMLElement;
                const cloneSelect = clone.querySelector<HTMLSelectElement>('select');
                if (cloneSelect) cloneSelect.value = '';
                this.parentNode.insertBefore(clone, this);
            }
            updateFilters();
        });
    }

    updateFilters();
}

/*---- Mail theme preview lightbox ----*/
GLightbox({ selector: '.themeBoxes a' });

/*---- Mail-theme radio: highlight the picked theme card ----*/
document.querySelectorAll<HTMLInputElement>("input[name='mail_theme']").forEach((radio) => {
    radio.addEventListener('change', function(this: HTMLInputElement) {
        document.querySelectorAll<HTMLInputElement>("input[name='mail_theme']").forEach((r) => {
            r.closest('.themeSelect')?.classList.remove('themeDefault');
        });
        this.closest('.themeSelect')?.classList.add('themeDefault');
    });
});

/*---- email_admin_on_new_user_filter: show the group picker only for "group" ----*/
document.querySelectorAll<HTMLInputElement>("input[name='email_admin_on_new_user_filter']").forEach((radio) => {
    radio.addEventListener('change', () => {
        const checked = document.querySelector<HTMLInputElement>("input[name='email_admin_on_new_user_filter']:checked");
        const val = checked?.value ?? '';
        const groupOpts = document.getElementById('email_admin_on_new_user_filter_group_options');
        if (groupOpts) groupOpts.style.display = val === 'group' ? '' : 'none';
    });
});
