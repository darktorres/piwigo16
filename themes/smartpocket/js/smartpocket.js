(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var thumbs = document.querySelector('ul.thumbnails');
        if (!thumbs || typeof GLightbox === 'undefined') return;

        var lb = GLightbox({
            selector: '.thumbnails a[href]',
            loop: typeof var_loop !== 'undefined' ? var_loop : false,
            autoplayVideos: false,
            touchNavigation: true,
        });

        // Log photo history on slide change
        lb.on('slide_changed', function (prev, current) {
            if (!current || !current.slideNode) return;
            var a = current.slideNode.closest('li')
                ? current.slideNode.closest('li').querySelector('a[data-image-id]')
                : null;
            if (!a) {
                // Try to find link by matching src
                var links = document.querySelectorAll('.thumbnails a[data-image-id]');
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

        if (typeof SPThumbs !== 'undefined' && typeof SPThumbsOpts !== 'undefined') {
            new SPThumbs(SPThumbsOpts);
        }
    });
})();
