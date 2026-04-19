import GLightbox from 'glightbox';
import { SPThumbs } from './thumb.arrange.ts';

export function initSmartpocket(): void {
    const thumbs = document.getElementById('thumbnails');
    if (!thumbs) return;

    if (document.activeElement && document.activeElement.closest('#thumbnails')) {
        (document.activeElement as HTMLElement).blur();
    }

    const lb = GLightbox({
        selector: '#thumbnails a[href]',
        loop: typeof window.var_loop !== 'undefined' ? window.var_loop : false,
        autoplayVideos: false,
        touchNavigation: true,
    });

    lb.on('slide_changed', function (...args: unknown[]) {
        const [, current] = args as [unknown, { slideNode?: Element; index?: number } | null];
        if (!current || !current.slideNode) return;
        const slideNode = current.slideNode;
        const li = slideNode.closest('li');
        let a = li ? li.querySelector<HTMLAnchorElement>('a[data-image-id]') : null;
        if (!a) {
            const links = document.querySelectorAll<HTMLAnchorElement>('#thumbnails a[data-image-id]');
            a = (current.index !== undefined ? links[current.index] : null) ?? null;
        }
        if (!a) return;
        void fetch('ws.php?format=json&method=pwg.history.log', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                image_id: a.getAttribute('data-image-id') || '',
                cat_id: thumbs.dataset['cat_id'] || '',
                section: thumbs.dataset['section'] || '',
                tags_string: thumbs.dataset['tags_string'] || '',
            }),
        });
    });

    if (window.SPThumbsOpts) {
        new SPThumbs({ ...window.SPThumbsOpts, extraRowHeight: 0 });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSmartpocket);
} else {
    initSmartpocket();
}
