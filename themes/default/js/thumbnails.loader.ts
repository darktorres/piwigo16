if (typeof max_requests === 'undefined') {
    (window as any).max_requests = 3;
}

const thumbnails_queue = jQuery.manageAjax.create('queued', {
    queue: true,
    cacheResponse: false,
    maxRequests: max_requests ?? 3,
    preventDoubleRequests: false,
});

function add_thumbnail_to_queue(img: JQuery, loop: number): void {
    thumbnails_queue.add({
        type: 'GET',
        url: img.data('src') as string,
        data: { ajaxload: 'true' },
        dataType: 'json',
        beforeSend: () => { jQuery('.loader').show(); },
        success: (result: { url: string }) => {
            img.attr('src', result.url);
            jQuery('.loader').hide();
        },
        error: () => {
            if (loop < 3) add_thumbnail_to_queue(img, ++loop);
            if (typeof error_icon !== 'undefined') img.attr('src', error_icon);
            jQuery('.loader').hide();
        },
    });
}

function pwg_ajax_thumbnails_loader(): void {
    jQuery('img[data-src]').each(function () {
        add_thumbnail_to_queue(jQuery(this), 0);
    });
}

jQuery(document).ready(pwg_ajax_thumbnails_loader);
