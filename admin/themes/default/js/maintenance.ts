import { getPageData } from './page-data';

interface MaintenancePageData {
    unit_MB: string;
    no_time_elapsed: string;
    pwg_token: string;
    gallery_locked: boolean;
    str_lock_gallery_tip: string;
    str_lock_gallery_confirm: string;
    str_unlock_gallery_confirm: string;
    str_purge_history_detail: string;
    str_purge_history_summary: string;
    str_purge_search_history: string;
    str_delete_all_sizes_confirm: string;
}

const pageData = getPageData<MaintenancePageData>();
const { unit_MB, no_time_elapsed } = pageData;

function displayResponse(domElem: HTMLElement[], values: string[], mDivs: NodeListOf<Element>, mValues: Record<string, string>): void {
    for (let index = 0; index < domElem.length; index++) {
        domElem[index].innerHTML = unit_MB.replace('%s', values[index]);
    }
    for (let index = 0; index < mDivs.length; index++) {
        const mDivName = (mDivs[index] as HTMLElement).getAttribute('name') ?? '';
        (mDivs[index] as HTMLElement).title = unit_MB.replace('%s', mValues[mDivName]);
    }
    document.querySelectorAll<HTMLElement>('.cache-lastCalculated-value')
        .forEach(el => { el.innerHTML = no_time_elapsed; });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll<HTMLElement>('.refresh-cache-size').forEach(btn => {
        btn.addEventListener('click', () => {
            btn.querySelector('.refresh-icon')?.classList.add('animate-spin');
            fetch('ws.php?format=json&method=pwg.getCacheSize', {
                method: 'POST',
                body: new URLSearchParams({ param: 'test_param', service: 'test_service' }),
            })
                .then(r => r.json())
                .then((data: any) => {
                    if (data.stat !== 'ok') return;
                    const domElemToRefresh: HTMLElement[] = [
                        document.querySelector<HTMLElement>('.cache-size-value')!,
                        document.querySelector<HTMLElement>('.multiple-pictures-sizes')!,
                        document.querySelector<HTMLElement>('.multiple-compiledTemplate-sizes')!,
                    ];
                    const domElemValues: string[] = [
                        data.result.infos[0].value,
                        data.result.infos[1].value.all,
                        data.result.infos[2].value,
                    ];
                    for (let i = 0; i < domElemValues.length; i++) {
                        domElemValues[i] = (Number(domElemValues[i]) / 1024 / 1024).toFixed(2);
                    }
                    const multipleSizes = document.querySelectorAll('.delete-check-container .delete-size-check');
                    const multipleSizesValues: Record<string, string> = data.result.infos[1].value;
                    for (const key of Object.keys(multipleSizesValues)) {
                        (multipleSizesValues as any)[key] = (Number((multipleSizesValues as any)[key]) / 1024 / 1024).toFixed(2);
                    }
                    displayResponse(domElemToRefresh, domElemValues, multipleSizes, multipleSizesValues);
                    document.querySelectorAll('.animate-spin').forEach(el => el.classList.remove('animate-spin'));
                })
                .catch((message) => console.log(message));
        });
    });
});

/*---- Action confirmations + size-checks (migrated from {footer_script}) ----*/

function attachConfirm(selector: string, getTitle: (el: HTMLElement) => string): void {
    document.querySelectorAll<HTMLAnchorElement>(selector).forEach((el) => {
        el.addEventListener('click', (e) => {
            e.preventDefault();
            if (window.confirm(getTitle(el))) {
                window.location.href = el.getAttribute('href') ?? '';
            }
        });
    });
}

const lockTitle = pageData.gallery_locked
    ? pageData.str_unlock_gallery_confirm
    : pageData.str_lock_gallery_confirm;
attachConfirm('.lock-gallery-button', () => lockTitle + '\n' + pageData.str_lock_gallery_tip);
attachConfirm('.purge-history-detail-button',  () => pageData.str_purge_history_detail);
attachConfirm('.purge-history-summary-button', () => pageData.str_purge_history_summary);
attachConfirm('.purge-search-history-button',  () => pageData.str_purge_search_history);
attachConfirm('.delete-all-sizes-button',      () => pageData.str_delete_all_sizes_confirm);

document.querySelectorAll<HTMLElement>('.delete-size-check').forEach((el) => {
    el.addEventListener('click', () => {
        const icon = el.querySelector<HTMLElement>('i');
        if (el.getAttribute('data-selected') === '1') {
            el.setAttribute('data-selected', '0');
            if (icon) icon.style.display = 'none';
        } else {
            el.setAttribute('data-selected', '1');
            if (icon) icon.style.display = '';
        }
        el.dispatchEvent(new Event('change'));
    });
});

const firstCheck = document.querySelector<HTMLElement>('.delete-size-check');
if (firstCheck) {
    firstCheck.addEventListener('change', () => {
        if (firstCheck.getAttribute('data-selected') === '1') {
            document.querySelectorAll<HTMLElement>('.delete-size-check').forEach((x) => {
                x.style.display = 'none';
                x.setAttribute('data-selected', '1');
            });
            firstCheck.style.display = '';
        } else {
            document.querySelectorAll<HTMLElement>('.delete-size-check').forEach((x) => {
                x.style.display = '';
                x.setAttribute('data-selected', '0');
            });
        }
    });
}

const delete_deriv_URL = 'admin.php?page=maintenance&action=derivatives&';
document.querySelectorAll<HTMLElement>('.delete-size-check').forEach((el) => {
    el.addEventListener('change', () => {
        const delete_deriv_with_token = delete_deriv_URL + 'pwg_token=' + pageData.pwg_token + '&';
        const selected: string[] = [];
        document.querySelectorAll<HTMLElement>('.delete-size-check').forEach((x) => {
            if (x.getAttribute('data-selected') === '1') {
                const name = x.getAttribute('name');
                if (name) selected.push(name);
            }
        });
        const deleteSizes = document.querySelector<HTMLAnchorElement>('.delete-sizes');
        if (!deleteSizes) return;
        if (selected.length === 0) {
            deleteSizes.setAttribute('href', '');
        } else {
            const types_str = selected[0] === 'all' ? 'all' : selected.join('_');
            deleteSizes.setAttribute('href', delete_deriv_with_token + 'type=' + types_str);
        }
    });
});

const deleteSizesEl = document.querySelector<HTMLElement>('.delete-sizes');
if (deleteSizesEl) {
    deleteSizesEl.style.display = 'none';
}
document.querySelectorAll<HTMLElement>('.delete-size-check').forEach((el) => {
    el.addEventListener('click', () => {
        let displayDeleteSizes = false;
        document.querySelectorAll<HTMLElement>('.delete-size-check').forEach((x) => {
            if (x.getAttribute('data-selected') === '1') {
                displayDeleteSizes = true;
            }
        });
        const ds = document.querySelector<HTMLElement>('.delete-sizes');
        if (ds) ds.style.display = displayDeleteSizes ? '' : 'none';
    });
});

export {};
