/**
 * Read server-side page data injected by the PHP controller as a
 * <script id="pwg-page-data" type="application/json"> tag.
 *
 * Usage:
 *   interface MyPageData { pwg_token: string; nb_items: number; }
 *   const d = getPageData<MyPageData>();
 */
export function getPageData<T>(id = 'pwg-page-data'): T {
    const el = document.getElementById(id);
    if (el === null || el.textContent === '') throw new Error(`${id} script element not found`);
    return JSON.parse(el.textContent) as T;
}
