// Keyboard navigation for picture pages. The destination URLs and key
// bindings are read from data-* attributes on .navigationButtons so the
// keymap stays in sync with whichever buttons the template chose to render.

const nav = document.querySelector<HTMLElement>('.navigationButtons');
if (nav) {
    document.addEventListener('keydown', (e) => {
        if (e.altKey) return;
        const target = e.target as HTMLElement | null;
        // Skip when typing in an editable element.
        if (target && (target as HTMLInputElement).type) return;

        const docElem = document.documentElement;
        const ds = nav.dataset;
        const ctrl = e.ctrlKey;
        let url: string | undefined;

        switch (e.keyCode || e.which) {
            case 63235: case 39: // Right
                if (ctrl || docElem.scrollLeft === docElem.scrollWidth - docElem.clientWidth) {
                    url = ds['next'];
                }
                break;
            case 63234: case 37: // Left
                if (ctrl || docElem.scrollLeft === 0) {
                    url = ds['previous'];
                }
                break;
            case 36: // Home
                if (ctrl) url = ds['first'];
                break;
            case 35: // End
                if (ctrl) url = ds['last'];
                break;
            case 38: // Up
                if (ctrl) url = ds['up'];
                break;
            case 32: // Space — play/pause toggles the slideshow
                url = ds['slideshowPlay'] ?? ds['slideshowPause'];
                break;
        }

        if (url) {
            e.preventDefault();
            window.location.href = url.replace(/&amp;/g, '&');
        }
    });
}
