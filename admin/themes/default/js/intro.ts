interface StorageDetail { filesize: number; nb_files: number }
interface StorageType { total: StorageDetail; details?: Record<string, StorageDetail>; _translate?: string }
interface IntroConfig {
    CHECK_FOR_UPDATES?: boolean;
    piwigo_need_update_msg?: string;
    ext_need_update_msg?: string;
    storage_total?: number;
    storage_details?: Record<string, StorageType>;
    str_gb_used?: string;
    str_mb_used?: string;
    str_gb?: string;
    str_mb?: string;
    translate_files?: string;
}

document.addEventListener('DOMContentLoaded', function () {
    const cfg = JSON.parse((document.getElementById('pwg-intro-data')?.textContent) ?? '{}') as IntroConfig;

    document.querySelectorAll<HTMLElement>('.cluetip').forEach(function (el) {
        const raw = el.getAttribute('title') ?? '';
        el.removeAttribute('title');
        el.dataset['cluetip'] = raw;
        let activeTip: HTMLDivElement | null = null;
        el.addEventListener('mouseenter', function (e: MouseEvent) {
            const parts = (el.dataset['cluetip'] ?? '').split('|');
            const tip = document.createElement('div');
            tip.className = 'pwg-cluetip';
            tip.style.cssText = 'position:absolute;z-index:9999;background:#fff;border:1px solid #ccc;padding:8px;width:300px;box-shadow:2px 2px 6px rgba(0,0,0,.2);font-size:13px;';
            tip.innerHTML = '<strong>' + (parts[0] ?? '') + '</strong>' + (parts[1] ? '<hr style="margin:4px 0">' + parts[1] : '');
            document.body.appendChild(tip);
            const rect = el.getBoundingClientRect();
            tip.style.left = (rect.left + window.scrollX) + 'px';
            tip.style.top = (rect.bottom + window.scrollY + 4) + 'px';
            activeTip = tip;
            void e;
        });
        el.addEventListener('mouseleave', function () {
            activeTip?.remove();
            activeTip = null;
        });
    });

    if (cfg.CHECK_FOR_UPDATES) {
        void fetch('ws.php?method=pwg.extensions.checkUpdates&format=json')
            .then(r => r.json())
            .then(function (data: { stat: string; result: { piwigo_need_update: boolean; ext_need_update: boolean } }) {
                if (data.stat !== 'ok') return;
                const piwigo_update = data.result.piwigo_need_update;
                const ext_update = data.result.ext_need_update;
                if (piwigo_update || ext_update) {
                    let warningsEl = document.querySelector(".warnings");
                    if (!warningsEl || warningsEl.tagName !== 'DIV') {
                        const eiwEl = document.querySelector(".eiw");
                        if (eiwEl) eiwEl.insertAdjacentHTML('afterbegin',
                            '<div class="warnings"><i class="eiw-icon icon-attention"></i><ul></ul></div>');
                    }
                }
                if (piwigo_update) {
                    const warningsUl = document.querySelector(".warnings ul");
                    if (warningsUl) warningsUl.insertAdjacentHTML('beforeend', '<li>' + (cfg.piwigo_need_update_msg ?? '') + '</li>');
                }
                if (ext_update) {
                    const warningsUl = document.querySelector(".warnings ul");
                    if (warningsUl) warningsUl.insertAdjacentHTML('beforeend', '<li>' + (cfg.ext_need_update_msg ?? '') + '</li>');
                }
            });
    }

    document.querySelectorAll('.newsletter-subscription a').forEach(function (el) {
        el.addEventListener('click', function (event) {
            document.querySelectorAll<HTMLElement>('.newsletter-subscription').forEach(function (ns) { ns.style.display = 'none'; });
            void fetch('admin.php?action=hide_newsletter_subscription');
            if ((el as HTMLElement).classList.contains('newsletter-hide')) event.preventDefault();
        });
    });

    const storageTotal = cfg.storage_total ?? 0;
    const size_info = storageTotal > 1000000 ? (cfg.str_gb_used ?? '') : (cfg.str_mb_used ?? '');
    const size_nb = storageTotal > 1000000 ? (storageTotal / 1000000).toFixed(2) : (storageTotal / 1000).toFixed(0);
    const chartTitleInfos = document.querySelector<HTMLElement>(".chart-title-infos");
    if (chartTitleInfos) chartTitleInfos.innerHTML = size_info.replace("%s", size_nb);

    const translate_type: Record<string, string> = {};
    const storageDetails = cfg.storage_details ?? {};
    Object.keys(storageDetails).forEach(function (type) {
        translate_type[type] = storageDetails[type]?._translate ?? type;
    });

    Object.entries(storageDetails).forEach(([type, infos]) => {
        const size = infos.total.filesize;
        const str_size_type_string = size > 1000000 ? (cfg.str_gb ?? '') : (cfg.str_mb ?? '');
        const size_nb2 = size > 1000000 ? (size / 1000000).toFixed(2) : (size / 1000).toFixed(0);
        const str_size = str_size_type_string.replace("%s", size_nb2);

        const storageTitleEl = document.getElementById('storage-title-' + type);
        if (storageTitleEl) storageTitleEl.innerHTML = '<b>' + translate_type[type] + '</b>';
        const storageSizeEl = document.getElementById('storage-size-' + type);
        if (storageSizeEl) storageSizeEl.innerHTML = '<b>' + str_size + '</b>';
        const storageFilesEl = document.getElementById('storage-files-' + type);
        if (storageFilesEl) storageFilesEl.innerHTML = '<p>' + (infos.total.nb_files ? (cfg.translate_files ?? '').replace('%d', String(infos.total.nb_files)) : '~') + '</p>';

        if (infos.details) {
            Object.keys(infos.details).forEach(function (ext) {
                const data = infos.details![ext]!;
                const detail_size = data.filesize;
                let detail_str_size_type: string;
                let detail_size_nb: string;
                if (detail_size > 1000000) {
                    detail_str_size_type = cfg.str_gb ?? '';
                    detail_size_nb = (detail_size / 1000000).toFixed(2);
                } else {
                    detail_str_size_type = cfg.str_mb ?? '';
                    detail_size_nb = parseFloat((detail_size / 1000).toFixed(0)) < 1
                        ? (detail_size / 1000).toFixed(2)
                        : (detail_size / 1000).toFixed(0);
                }
                const detail_str_size = detail_str_size_type.replace("%s", detail_size_nb);
                const storageDetailEl = document.getElementById('storage-detail-' + type);
                if (storageDetailEl) storageDetailEl.insertAdjacentHTML('beforeend',
                    '<span class="tooltip-details-cont">' +
                    '<span class="tooltip-details-ext"><b>' + ext + '</b></span>' +
                    '<span class="tooltip-details-size"><b>' + detail_str_size + '</b></span>' +
                    '<span class="tooltip-details-files">' + (cfg.translate_files ?? '').replace('%d', String(data.nb_files)) + '</span>' +
                    '</span>');
                const chartSpan = document.querySelector<HTMLElement>('.storage-chart span[data-type="storage-' + type + '"]');
                const ext_bg_color = chartSpan ? window.getComputedStyle(chartSpan).backgroundColor : '';
                document.querySelectorAll<HTMLElement>('#storage-' + type + ' .tooltip-details-ext b').forEach(function (b) {
                    b.style.color = ext_bg_color;
                });
            });
        } else {
            document.querySelectorAll<HTMLElement>('#storage-' + type + ' .separated').forEach(function (el) {
                el.setAttribute('style', 'display: none !important');
            });
            document.querySelectorAll<HTMLElement>('#storage-' + type + ' .tooltip-header').forEach(function (el) {
                el.style.margin = '0';
            });
        }

        const tooltipEl = document.getElementById('storage-' + type);
        if (tooltipEl) {
            tooltipEl.addEventListener('mouseenter', function () {
                tooltipEl.style.display = 'block';
                document.querySelectorAll<HTMLElement>('.storage-chart span[data-type="storage-' + type + '"] p').forEach(p => { p.style.opacity = '0.4'; });
            });
            tooltipEl.addEventListener('mouseleave', function () {
                tooltipEl.style.display = 'none';
                document.querySelectorAll<HTMLElement>('.storage-chart span[data-type="storage-' + type + '"] p').forEach(p => { p.style.opacity = '0'; });
            });
        }
        document.querySelectorAll<HTMLElement>('.storage-chart span[data-type="storage-' + type + '"]').forEach(function (span) {
            span.addEventListener('mouseenter', function () {
                span.querySelectorAll<HTMLElement>('p').forEach(p => { p.style.opacity = '0.4'; });
            });
            span.addEventListener('mouseleave', function () {
                span.querySelectorAll<HTMLElement>('p').forEach(p => { p.style.opacity = '0'; });
            });
        });
    });

    function positionStorageTooltip(el: HTMLElement): void {
        const tooltipId = el.dataset['type'];
        const tooltip = document.querySelector<HTMLElement>('.storage-tooltips #' + tooltipId);
        const arrow = document.querySelector<HTMLElement>('.storage-tooltips #' + tooltipId + ' .tooltip-arrow');
        if (!tooltip || !arrow) return;

        let left = el.offsetLeft + el.getBoundingClientRect().width / 2 - tooltip.clientWidth / 2;
        const chartTitleEl = document.getElementById('chart-title-storage');
        const storage_width = chartTitleEl ? chartTitleEl.clientWidth : 0;
        if (left + tooltip.clientWidth > storage_width) {
            const diff = (left + tooltip.clientWidth) - storage_width;
            left -= diff;
            arrow.style.left = 'calc(50% + ' + diff + 'px)';
        }
        tooltip.style.left = left + "px";
        const storageChart = document.querySelector('.storage-chart');
        const str_chart_pos = storageChart ? storageChart.getBoundingClientRect().top + window.scrollY : 0;
        const str_chart_height = storageChart ? (storageChart as HTMLElement).clientHeight : 0;
        const tooltip_height = tooltip.clientHeight + str_chart_height;
        if (str_chart_pos + tooltip_height > window.innerHeight) {
            tooltip.style.bottom = 'calc(100% + ' + str_chart_height + 'px)';
            arrow.classList.add('bottom');
        } else {
            tooltip.style.bottom = '';
            arrow.classList.remove('bottom');
        }
    }

    document.querySelectorAll<HTMLElement>('.storage-chart span').forEach(function (el) {
        const tooltipId = el.dataset['type'];
        const tooltip = document.querySelector<HTMLElement>('.storage-tooltips #' + tooltipId);
        positionStorageTooltip(el);
        if (tooltip) {
            const toggleFn = function () {
                tooltip.style.display = tooltip.style.display === 'none' ? '' : 'none';
            };
            el.addEventListener('mouseenter', toggleFn);
            el.addEventListener('mouseleave', toggleFn);
        }
    });

    window.addEventListener('resize', function () {
        document.querySelectorAll<HTMLElement>('.storage-chart span').forEach(positionStorageTooltip);
    });
});
