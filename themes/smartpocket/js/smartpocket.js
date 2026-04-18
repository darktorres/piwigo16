export function initSmartpocket() {
    const thumbs = document.getElementById('thumbnails');
    if (!thumbs || typeof GLightbox === 'undefined') return;

    if (document.activeElement && document.activeElement.closest('#thumbnails')) {
        document.activeElement.blur();
    }

    const lb = GLightbox({
        selector: '#thumbnails a[href]',
        loop: typeof window.var_loop !== 'undefined' ? window.var_loop : false,
        autoplayVideos: false,
        touchNavigation: true,
    });

    lb.on('slide_changed', function (prev, current) {
        if (!current || !current.slideNode) return;
        let a = current.slideNode.closest('li')
            ? current.slideNode.closest('li').querySelector('a[data-image-id]')
            : null;
        if (!a) {
            const links = document.querySelectorAll('#thumbnails a[data-image-id]');
            a = links[current.index] || null;
        }
        if (!a) return;
        fetch('ws.php?format=json&method=pwg.history.log', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                image_id: a.getAttribute('data-image-id') || '',
                cat_id: thumbs.dataset.cat_id || '',
                section: thumbs.dataset.section || '',
                tags_string: thumbs.dataset.tags_string || '',
            }),
        });
    });

    if (typeof window.SPThumbs !== 'undefined' && typeof window.SPThumbsOpts !== 'undefined') {
        new window.SPThumbs(window.SPThumbsOpts);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSmartpocket);
} else {
    initSmartpocket();
}
