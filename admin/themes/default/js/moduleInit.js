/**
 * Bootstrap utility for admin page modules
 * Reads config from JSON island and calls init function when DOM is ready
 */

export function initModule(initFn) {
    const el = document.getElementById('pwg-page-data');
    if (!el) return;

    const cfg = JSON.parse(el.textContent);

    if (document.readyState !== 'loading') {
        initFn(cfg);
    } else {
        document.addEventListener('DOMContentLoaded', () => initFn(cfg));
    }
}
