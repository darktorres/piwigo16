declare var storage_details: Record<string, {
    total: { filesize: number; nb_files: number };
    details?: Record<string, { filesize: number; nb_files: number }>;
}>;
declare var str_gb: string;
declare var str_mb: string;
declare var translate_type: Record<string, string>;
declare var translate_files: string;

function posLeft(el: HTMLElement): number {
    const rect = el.getBoundingClientRect();
    const parentRect = (el.offsetParent as HTMLElement | null)?.getBoundingClientRect() ?? { left: 0 };
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
        if (filesEl) filesEl.innerHTML = '<p>' + (infos.total.nb_files ? translate_files.replace('%d', String(infos.total.nb_files)) : '~') + '</p>';

        if (infos.details) {
            const detailContainer = document.getElementById(`storage-detail-${type}`);
            const chartSpan = document.querySelector<HTMLElement>(`.storage-chart span[data-type="storage-${type}"]`);
            const extBgColor = chartSpan ? getComputedStyle(chartSpan).backgroundColor : '';
            Object.entries(infos.details).forEach(([ext, data]) => {
                const detail_size = data.filesize;
                let detail_str_size: string;
                if (detail_size > 1048576) {
                    detail_str_size = str_gb.replace('%s', (detail_size / 1048576).toFixed(2));
                } else {
                    const raw = (detail_size / 1024).toFixed(0);
                    detail_str_size = str_mb.replace('%s', Number(raw) < 1 ? (detail_size / 1024).toFixed(2) : raw);
                }
                detailContainer?.insertAdjacentHTML('beforeend',
                    '<span class="tooltip-details-cont">' +
                    '<span class="tooltip-details-ext"><b>' + ext + '</b></span>' +
                    '<span class="tooltip-details-size"><b>' + detail_str_size + '</b></span>' +
                    '<span class="tooltip-details-files">' + translate_files.replace('%d', String(data.nb_files)) + '</span>' +
                    '</span>'
                );
                document.querySelectorAll<HTMLElement>(`#storage-${type} .tooltip-details-ext b`)
                    .forEach(el => { el.style.color = extBgColor; });
            });
        } else {
            const separated = document.querySelector<HTMLElement>(`#storage-${type} .separated`);
            if (separated) separated.setAttribute('style', 'display: none !important');
            const header = document.querySelector<HTMLElement>(`#storage-${type} .tooltip-header`);
            if (header) header.style.margin = '0';
        }

        const storageEl = document.getElementById(`storage-${type}`);
        const chartSpan = document.querySelector<HTMLElement>(`.storage-chart span[data-type="storage-${type}"]`);
        const chartSpanP = document.querySelector<HTMLElement>(`.storage-chart span[data-type="storage-${type}"] p`);
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
    document.querySelectorAll<HTMLElement>('.storage-chart span').forEach(span => {
        const type = span.dataset['type'] ?? '';
        const tooltip = document.querySelector<HTMLElement>(`.storage-tooltips #${type}`);
        const arrow = document.querySelector<HTMLElement>(`.storage-tooltips #${type} .tooltip-arrow`);
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
        const str_chart_height = document.querySelector<HTMLElement>('.storage-chart')?.clientHeight ?? 0;
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
            span.addEventListener('mouseenter', () => { tooltip.style.display = ''; });
            span.addEventListener('mouseleave', () => { tooltip.style.display = 'none'; });
        }
    });
}

function resizeActivityTooltips(): void {
    document.querySelectorAll<HTMLElement>('.activity_tooltips').forEach(container => {
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
