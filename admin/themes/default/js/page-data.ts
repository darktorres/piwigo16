/**
 * Read server-side page data injected by the PHP controller as a
 * <script id="pwg-page-data" type="application/json"> tag.
 *
 * Usage:
 *   interface MyPageData { pwg_token: string; nb_items: number; }
 *   const d = getPageData<MyPageData>();
 */
export function getPageData<T>(): T {
    const el = document.getElementById('pwg-page-data');
    if (!el?.textContent) throw new Error('pwg-page-data script element not found');
    return JSON.parse(el.textContent) as T;
}
