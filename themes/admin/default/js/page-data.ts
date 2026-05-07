/**
 * Read server-side page data injected by the PHP controller as a
 * <script id="pwg-page-data" type="application/json"> tag.
 *
 * Usage:
 *   interface MyPageData { pwg_token: string; nb_items: number; }
 *   const d = getPageData<MyPageData>();
 *   const d = getPageData<MyPageData>('pwg-filter-page-data');
 */
export function getPageData<T>(id = 'pwg-page-data'): T {
    const el = document.getElementById(id);
    if (!el) throw new Error(`#${id} not found in DOM`);
    if (!el.textContent?.trim()) throw new Error(`#${id} exists but is empty`);
    return JSON.parse(el.textContent) as T;
}
