import { getPageData } from './page-data';

interface IntroTooltipsPageData {
    storage_details: Record<
        string,
        {
            total: { filesize: number; nb_files: number };
            details?: Record<string, { filesize: number; nb_files: number }>;
        }
    >;
    str_gb: string;
    str_mb: string;
    translate_type: Record<string, string>;
    translate_files: string;
    dashboard: {
        check_for_updates: boolean;
        storage_total: number;
        str_gb_used: string;
        str_mb_used: string;
        str_piwigo_need_update: string;
        str_ext_need_update: string;
        newsletter?: {
            email: string;
            subscribe_base_url: string;
            old_newsletters_url: string;
            str_subscribe_title: string;
            str_subscribe_button: string;
            str_see_previous: string;
            str_dismiss: string;
        };
    };
}

const { storage_details, str_gb, str_mb, translate_type, translate_files, dashboard } =
    getPageData<IntroTooltipsPageData>();

/*---- Dashboard extras (migrated from intro.tpl {footer_script}) ----*/

const piwigo_need_update_msg = `<a href="admin.php?page=updates">${dashboard.str_piwigo_need_update} <i class="icon-right"></i></a>`;
const ext_need_update_msg = `<a href="admin.php?page=updates&tab=ext">${dashboard.str_ext_need_update} <i class="icon-right"></i></a>`;

if (dashboard.check_for_updates) {
    fetch('ws.php?format=json&method=pwg.extensions.checkUpdates', {
        signal: AbortSignal.timeout(5000),
    })
        .then((r) => r.json())
        .then(
            (data: {
                stat?: string;
                result?: { piwigo_need_update?: boolean; ext_need_update?: boolean };
            }) => {
                if (data.stat !== 'ok') return;
                const piwigo_update = data.result?.piwigo_need_update;
                const ext_update = data.result?.ext_need_update;
                if ((piwigo_update || ext_update) && !document.querySelector('.warnings')) {
                    document
                        .querySelector('.eiw')
                        ?.insertAdjacentHTML(
                            'afterbegin',
                            '<div class="warnings"><i class="eiw-icon icon-attention"></i><ul></ul></div>'
                        );
                }
                if (piwigo_update) {
                    document
                        .querySelector('.warnings ul')
                        ?.insertAdjacentHTML(
                            'beforeend',
                            '<li>' + piwigo_need_update_msg + '</li>'
                        );
                }
                if (ext_update) {
                    document
                        .querySelector('.warnings ul')
                        ?.insertAdjacentHTML('beforeend', '<li>' + ext_need_update_msg + '</li>');
                }
            }
        )
        .catch(() => {
            /* best-effort */
        });
}

if (dashboard.newsletter) {
    const nl = dashboard.newsletter;
    document.querySelector('.eiw')?.insertAdjacentHTML(
        'afterbegin',
        `
  <div class="promote-newsletter">
    <div class="promote-content">
      <img class="promote-image" src="admin/themes/default/images/promote-newsletter.png">
      <div class="promote-newsletter-content">
        <span class="promote-newsletter-title">${nl.str_subscribe_title}</span>
        <div class="promote-content subscribe-newsletter">
          <input type="text" id="newsletterSubscribeInput" value="${nl.email}" class="left-side">
          <a href="${nl.subscribe_base_url}${nl.email}" id="newsletterSubscribeLink" class="right-side go-to-porg icon-thumbs-up newsletter-hide">${nl.str_subscribe_button}</a>
        </div>
        <a href="${nl.old_newsletters_url}" class="promote-link">${nl.str_see_previous}</a>
      </div>
    </div>
    <a href="#" class="dont-show-again icon-cancel tiptip newsletter-hide" title="${nl.str_dismiss}"></a>
  </div>`
    );

    const nsi = document.getElementById('newsletterSubscribeInput') as HTMLInputElement | null;
    nsi?.addEventListener('change', () => {
        const link = document.getElementById('newsletterSubscribeLink');
        if (link) link.setAttribute('href', nl.subscribe_base_url + nsi.value);
    });

    document.querySelectorAll<HTMLElement>('.newsletter-hide').forEach((el) => {
        el.addEventListener('click', (e) => {
            const promo = document.querySelector<HTMLElement>('.promote-newsletter');
            if (promo) promo.style.display = 'none';
            fetch('admin.php?action=hide_newsletter_subscription').catch(() => {
                /* best-effort */
            });
            if (el.classList.contains('newsletter-hide')) {
                e.preventDefault();
            }
        });
    });
}

const size_info = dashboard.storage_total > 1000000 ? dashboard.str_gb_used : dashboard.str_mb_used;
const size_nb =
    dashboard.storage_total > 1000000
        ? (dashboard.storage_total / 1000000).toFixed(2)
        : (dashboard.storage_total / 1000).toFixed(0);
const chartTitleEl = document.querySelector<HTMLElement>('.chart-title-infos');
if (chartTitleEl) chartTitleEl.innerHTML = size_info.replace('%s', size_nb);

function posLeft(el: HTMLElement): number {
    const rect = el.getBoundingClientRect();
    const parentRect = (el.offsetParent as HTMLElement | null)?.getBoundingClientRect() ?? {
        left: 0,
    };
    return rect.left - parentRect.left;
}

document.addEventListener('DOMContentLoaded', () => {
    Object.entries(storage_details).forEach(([type, infos]) => {
        const size = infos.total.filesize;
        const str_size_type_string = size > 1048576 ? str_gb : str_mb;
        const size_nb = size > 1048576 ? (size / 1048576).toFixed(2) : (size / 1024).toFixed(0);
        const str_size = str_size_type_string.replace('%s', size_nb);

        const titleEl = document.getElementById(`storage-title-${type}`);
        const sizeEl = document.getElementById(`storage-size-${type}`);
        const filesEl = document.getElementById(`storage-files-${type}`);
        if (titleEl) titleEl.innerHTML = '<b>' + translate_type[type] + '</b>';
        if (sizeEl) sizeEl.innerHTML = '<b>' + str_size + '</b>';
        if (filesEl)
            filesEl.innerHTML =
                '<p>' +
                (infos.total.nb_files
                    ? translate_files.replace('%d', String(infos.total.nb_files))
                    : '~') +
                '</p>';

        if (infos.details) {
            const detailContainer = document.getElementById(`storage-detail-${type}`);
            const chartSpan = document.querySelector<HTMLElement>(
                `.storage-chart span[data-type="storage-${type}"]`
            );
            const extBgColor = chartSpan ? getComputedStyle(chartSpan).backgroundColor : '';
            Object.entries(infos.details).forEach(([ext, data]) => {
                const detail_size = data.filesize;
                let detail_str_size: string;
                if (detail_size > 1048576) {
                    detail_str_size = str_gb.replace('%s', (detail_size / 1048576).toFixed(2));
                } else {
                    const raw = (detail_size / 1024).toFixed(0);
                    detail_str_size = str_mb.replace(
                        '%s',
                        Number(raw) < 1 ? (detail_size / 1024).toFixed(2) : raw
                    );
                }
                detailContainer?.insertAdjacentHTML(
                    'beforeend',
                    '<span class="tooltip-details-cont">' +
                        '<span class="tooltip-details-ext"><b>' +
                        ext +
                        '</b></span>' +
                        '<span class="tooltip-details-size"><b>' +
                        detail_str_size +
                        '</b></span>' +
                        '<span class="tooltip-details-files">' +
                        translate_files.replace('%d', String(data.nb_files)) +
                        '</span>' +
                        '</span>'
                );
                document
                    .querySelectorAll<HTMLElement>(`#storage-${type} .tooltip-details-ext b`)
                    .forEach((el) => {
                        el.style.color = extBgColor;
                    });
            });
        } else {
            const separated = document.querySelector<HTMLElement>(`#storage-${type} .separated`);
            if (separated) separated.setAttribute('style', 'display: none !important');
            const header = document.querySelector<HTMLElement>(`#storage-${type} .tooltip-header`);
            if (header) header.style.margin = '0';
        }

        const storageEl = document.getElementById(`storage-${type}`);
        const chartSpan = document.querySelector<HTMLElement>(
            `.storage-chart span[data-type="storage-${type}"]`
        );
        const chartSpanP = document.querySelector<HTMLElement>(
            `.storage-chart span[data-type="storage-${type}"] p`
        );
        if (storageEl) {
            storageEl.addEventListener('mouseenter', () => {
                storageEl.style.display = 'block';
                if (chartSpanP) chartSpanP.style.opacity = '0.4';
            });
            storageEl.addEventListener('mouseleave', () => {
                storageEl.style.display = 'none';
                if (chartSpanP) chartSpanP.style.opacity = '0';
            });
        }
        if (chartSpan) {
            chartSpan.addEventListener('mouseover', () => {
                chartSpan.querySelector<HTMLElement>('p')!.style.opacity = '0.4';
            });
            chartSpan.addEventListener('mouseout', () => {
                chartSpan.querySelector<HTMLElement>('p')!.style.opacity = '0';
            });
        }
    });

    resizeStorageTooltips();
    resizeActivityTooltips();
    window.addEventListener('resize', () => {
        resizeStorageTooltips(true);
        resizeActivityTooltips();
    });
});

function resizeStorageTooltips(resize = false): void {
    document.querySelectorAll<HTMLElement>('.storage-chart span').forEach((span) => {
        const type = span.dataset['type'] ?? '';
        const tooltip = document.querySelector<HTMLElement>(`.storage-tooltips #${type}`);
        const arrow = document.querySelector<HTMLElement>(
            `.storage-tooltips #${type} .tooltip-arrow`
        );
        if (!tooltip) return;
        const storage_width = document.getElementById('chart-title-storage')?.clientWidth ?? 0;
        let left = posLeft(span) + span.getBoundingClientRect().width / 2 - tooltip.clientWidth / 2;
        if (left + tooltip.clientWidth > storage_width) {
            const diff = left + tooltip.clientWidth - storage_width;
            left -= diff;
            if (arrow) arrow.style.left = `calc(50% + ${diff}px)`;
        }
        tooltip.style.left = left + 'px';
        const chartRect = document.querySelector('.storage-chart')?.getBoundingClientRect();
        const str_chart_pos = chartRect?.top ?? 0;
        const str_chart_height =
            document.querySelector<HTMLElement>('.storage-chart')?.clientHeight ?? 0;
        const tooltip_height = tooltip.clientHeight + str_chart_height;
        const windows_height = window.innerHeight;
        if (resize) {
            if (str_chart_pos + tooltip_height > windows_height) {
                tooltip.style.bottom = `calc(100% + ${str_chart_height}px)`;
                arrow?.classList.add('bottom');
            } else {
                tooltip.style.bottom = '';
                arrow?.classList.remove('bottom');
            }
        } else {
            if (str_chart_pos + tooltip_height > windows_height) {
                tooltip.style.bottom = `calc(100% + ${str_chart_height}px)`;
                arrow?.classList.add('bottom');
            }
            span.addEventListener('mouseenter', () => {
                tooltip.style.display = '';
            });
            span.addEventListener('mouseleave', () => {
                tooltip.style.display = 'none';
            });
        }
    });
}

function resizeActivityTooltips(): void {
    document.querySelectorAll<HTMLElement>('.activity_tooltips').forEach((container) => {
        const tooltip = container.querySelector<HTMLElement>('.tooltip');
        if (!tooltip) return;
        const max_width = (document.getElementById('pwgMain')?.clientWidth ?? 0) - 20;
        const left = posLeft(container) + container.clientWidth / 2 + tooltip.clientWidth / 2;
        if (left > max_width) {
            const arrow = container.querySelector<HTMLElement>('.tooltip-arrow');
            const diff = max_width - left;
            tooltip.style.left = `calc(50% + ${diff}px)`;
            if (arrow) arrow.style.left = `calc(50% - ${diff}px)`;
        }
    });
}

export {};
