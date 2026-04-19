export function initModule(initFn: (cfg: Record<string, unknown>) => void): void {
    const el = document.getElementById('pwg-page-data');
    if (!el) return;

    const cfg = JSON.parse(el.textContent) as Record<string, unknown>;

    if (document.readyState !== 'loading') {
        initFn(cfg);
    } else {
        document.addEventListener('DOMContentLoaded', () => initFn(cfg));
    }
}
