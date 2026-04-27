declare var storage_details: Record<string, {
    total: { filesize: number; nb_files: number };
    details?: Record<string, { filesize: number; nb_files: number }>;
}>;
declare var str_gb: string;
declare var str_mb: string;
declare var translate_type: Record<string, string>;
declare var translate_files: string;

$(function () {
    Object.entries(storage_details).forEach(([type, infos]) => {
        const size = infos.total.filesize;
        const str_size_type_string = size > 1048576 ? str_gb : str_mb;
        const size_nb = size > 1048576 ? (size / 1048576).toFixed(2) : (size / 1024).toFixed(0);
        const str_size = str_size_type_string.replace('%s', size_nb);

        $(`#storage-title-${type}`).html('<b>' + translate_type[type] + '</b>');
        $(`#storage-size-${type}`).html('<b>' + str_size + '</b>');
        $(`#storage-files-${type}`).html('<p>' + (infos.total.nb_files ? translate_files.replace('%d', String(infos.total.nb_files)) : '~') + '</p>');

        if (infos.details) {
            $.each(infos.details, function (ext, data) {
                const detail_size = data.filesize;
                let detail_str_size_type_string: string;
                let detail_size_nb: string;
                if (detail_size > 1048576) {
                    detail_str_size_type_string = str_gb;
                    detail_size_nb = (detail_size / 1048576).toFixed(2);
                } else {
                    const raw = (detail_size / 1024).toFixed(0);
                    detail_str_size_type_string = str_mb;
                    detail_size_nb = Number(raw) < 1 ? (detail_size / 1024).toFixed(2) : raw;
                }
                const detail_str_size = detail_str_size_type_string.replace('%s', detail_size_nb);
                $(`#storage-detail-${type}`).append(
                    '<span class="tooltip-details-cont">' +
                    '<span class="tooltip-details-ext"><b>' + ext + '</b></span>' +
                    '<span class="tooltip-details-size"><b>' + detail_str_size + '</b></span>' +
                    '<span class="tooltip-details-files">' + translate_files.replace('%d', String(data.nb_files)) + '</span>' +
                    '</span>'
                );
                const ext_bg_color = $(`.storage-chart span[data-type="storage-${type}"]`).css('background-color');
                $(`#storage-${type} .tooltip-details-ext b`).css('color', ext_bg_color);
            });
        } else {
            $(`#storage-${type} .separated`).attr('style', 'display: none !important');
            $(`#storage-${type} .tooltip-header`).css('margin', '0');
        }

        $(`#storage-${type}`).on('mouseenter', function () {
            $(this).css('display', 'block');
            $(`.storage-chart span[data-type="storage-${type}"] p`).css('opacity', '0.4');
        }).on('mouseleave', function () {
            $(this).css('display', 'none');
            $(`.storage-chart span[data-type="storage-${type}"] p`).css('opacity', '0');
        });

        $(`.storage-chart span[data-type="storage-${type}"]`).on('mouseover', function () {
            $(this).find('p').css('opacity', '0.4');
        }).on('mouseout', function () {
            $(this).find('p').css('opacity', '0');
        });
    });

    resizeStorageTooltips();
    resizeActivityTooltips();
    $(window).on('resize', function () {
        resizeStorageTooltips(true);
        resizeActivityTooltips();
    });
});

function resizeStorageTooltips(resize = false): void {
    $('.storage-chart span').each(function () {
        const tooltip = $(`.storage-tooltips #${$(this).data('type')}`);
        const arrow = $(`.storage-tooltips #${String($(this).data('type'))} .tooltip-arrow`);
        let left = $(this).position().left + ($(this).width() ?? 0) / 2 - (tooltip.innerWidth() ?? 0) / 2;
        const storage_width = $('#chart-title-storage').innerWidth() ?? 0;
        if (left + (tooltip.innerWidth() ?? 0) > storage_width) {
            const diff = left + (tooltip.innerWidth() ?? 0) - storage_width;
            left -= diff;
            arrow.css('left', `calc(50% + ${diff}px)`);
        }
        tooltip.css('left', left + 'px');
        const str_chart_pos = $('.storage-chart').offset()?.top ?? 0;
        const str_chart_height = $('.storage-chart').innerHeight() ?? 0;
        const tooltip_height = ($(`.storage-tooltips #${String($(this).data('type'))}`).innerHeight() ?? 0) + str_chart_height;
        const windows_height = $(window).height() ?? 0;

        if (resize) {
            if (str_chart_pos + tooltip_height > windows_height) {
                tooltip.css('bottom', `calc(100% + ${str_chart_height}px)`);
                arrow.addClass('bottom');
            } else {
                tooltip.css('bottom', '');
                arrow.removeClass('bottom');
            }
        } else {
            if (str_chart_pos + tooltip_height > windows_height) {
                tooltip.css('bottom', `calc(100% + ${str_chart_height}px)`);
                arrow.addClass('bottom');
            }
            $(this).off('mouseenter').on('mouseenter', function () { tooltip.show(); });
            $(this).off('mouseleave').on('mouseleave', function () { tooltip.hide(); });
        }
    });
}

function resizeActivityTooltips(): void {
    $('.activity_tooltips').has('.tooltip').each(function () {
        const max_width = ($('#pwgMain').innerWidth() ?? 0) - 20;
        const tooltip = $(this).find('.tooltip');
        const left = $(this).position().left + (($(this).innerWidth() ?? 0) / 2) + ((tooltip.innerWidth() ?? 0) / 2);
        if (left > max_width) {
            const arrow = $(this).find('.tooltip-arrow');
            const diff = max_width - left;
            tooltip.css('left', `calc(50% + ${diff}px)`);
            arrow.css('left', `calc(50% - ${diff}px)`);
        }
    });
}

export {};
