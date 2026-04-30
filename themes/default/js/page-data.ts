/**
 * Read server-side page data injected by the PHP controller as a
 * <script id="pwg-page-data" type="application/json"> tag.
 */
export function getPageData<T>(): T {
    const el = document.getElementById('pwg-page-data');
    if (!el?.textContent) throw new Error('pwg-page-data script element not found');
    return JSON.parse(el.textContent) as T;
}
