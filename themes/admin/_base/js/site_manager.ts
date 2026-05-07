import { getPageData } from './page-data';

interface SiteManagerPageData {
    str_delete_site_confirm: string;
}

declare function pwg_jconfirm_follow_href_fn(
    el: HTMLElement,
    options: { alert_title?: string }
): void;

const { str_delete_site_confirm } = getPageData<SiteManagerPageData>();

document.querySelector<HTMLAnchorElement>('#showCreateSite a')?.addEventListener('click', (e) => {
    e.preventDefault();
    const showEl = document.getElementById('showCreateSite');
    const createEl = document.getElementById('createSite');
    if (showEl) showEl.style.display = 'none';
    if (createEl) createEl.style.display = '';
});

document.querySelectorAll<HTMLAnchorElement>('.delete-site-button').forEach((el) => {
    pwg_jconfirm_follow_href_fn(el, { alert_title: str_delete_site_confirm });
});
