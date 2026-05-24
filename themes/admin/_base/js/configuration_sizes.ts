import '../css/pages/configuration_sizes.css';
import { getPageData } from './page-data';

interface ConfigurationSizesPageData {
    str_restore_confirm: string;
    str_max_width: string;
    str_width: string;
    str_max_height: string;
    str_height: string;
}

declare function pwg_jconfirm_follow_href_fn(
    el: HTMLElement,
    options: { alert_title?: string }
): void;

const pageData = getPageData<ConfigurationSizesPageData>();

document.querySelectorAll<HTMLAnchorElement>('.restore-settings-button').forEach((el) => {
    pwg_jconfirm_follow_href_fn(el, { alert_title: pageData.str_restore_confirm });
});

function toggleResizeFields(): void {
    const checkbox = document.querySelector<HTMLInputElement>('[name=original_resize]');
    const needToggle = document.getElementById('sizeEdit-original');
    if (!needToggle) return;
    needToggle.style.display = checkbox?.checked === true ? '' : 'none';
}

toggleResizeFields();
document
    .querySelector<HTMLInputElement>('[name=original_resize]')
    ?.addEventListener('click', toggleResizeFields);

document.querySelectorAll<HTMLAnchorElement>("a[id^='sizeEditOpen-']").forEach((el) => {
    el.addEventListener('click', (e) => {
        e.preventDefault();
        const sizeName = el.id.split('-')[1];
        const sizeEdit = document.getElementById('sizeEdit-' + sizeName);
        if (sizeEdit) sizeEdit.style.display = sizeEdit.style.display === 'none' ? '' : 'none';
        el.style.display = 'none';
    });
});

document.querySelectorAll<HTMLInputElement>('.cropToggle').forEach((el) => {
    el.addEventListener('click', () => {
        const form = el.closest<HTMLTableElement>('table.sizeEditForm');
        const labelBoxWidth = form?.querySelector<HTMLElement>('td.sizeEditWidth');
        const labelBoxHeight = form?.querySelector<HTMLElement>('td.sizeEditHeight');
        if (el.checked) {
            if (labelBoxWidth) labelBoxWidth.innerHTML = pageData.str_width;
            if (labelBoxHeight) labelBoxHeight.innerHTML = pageData.str_height;
        } else {
            if (labelBoxWidth) labelBoxWidth.innerHTML = pageData.str_max_width;
            if (labelBoxHeight) labelBoxHeight.innerHTML = pageData.str_max_height;
        }
    });
});

document.getElementById('showDetails')?.addEventListener('click', function (this: HTMLElement, e) {
    e.preventDefault();
    document.querySelectorAll<HTMLElement>('.sizeDetails').forEach((el) => {
        el.style.display = '';
    });
    this.style.visibility = 'hidden';
});
