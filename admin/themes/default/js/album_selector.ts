import { initModule } from './moduleInit.js';

interface AlbumSelectorConfig {
    apiMethod?: string;
    strNoSearchInProgress?: string;
    strAlbumsFound?: string;
    strAlbumFound?: string;
    strResultLimit?: string;
}

interface CategoryResult {
    id: string | number;
    fullname: string;
}

let apiMethod = '';
let strNoSearchInProgress = '';
let strAlbumsFound = '';
let strAlbumFound = '';
let strResultLimit = '';

export function set_up_popin(): void {
    document.querySelectorAll<HTMLElement>('.ClosePopIn').forEach(function (btn) {
        btn.addEventListener('click', function () { linked_albums_close(); });
    });
}

export function linked_albums_close(): void {
    const addLinkedAlbum = document.getElementById('addLinkedAlbum');
    if (addLinkedAlbum) addLinkedAlbum.style.display = 'none';
}

export function linked_albums_open(): void {
    const addLinkedAlbum = document.getElementById('addLinkedAlbum');
    if (addLinkedAlbum) addLinkedAlbum.style.display = '';
    const searchInput = document.querySelector<HTMLInputElement>('.search-input');
    if (searchInput) { searchInput.value = ''; searchInput.focus(); }
    const searchResult = document.getElementById('searchResult');
    if (searchResult) searchResult.innerHTML = '';
    const limitReached = document.querySelector<HTMLElement>('.limitReached');
    if (limitReached) limitReached.innerHTML = strNoSearchInProgress;
}

export function linked_albums_search(searchText: string): void {
    let apiParams: Record<string, unknown>;
    if (apiMethod === 'pwg.categories.getList') {
        apiParams = { cat_id: 0, recursive: true, fullname: true, search: searchText };
    } else {
        apiParams = { search: searchText, additional_output: 'full_name_with_admin_links' };
    }

    const searching = document.querySelector<HTMLElement>('.linkedAlbumPopInContainer .searching');
    if (searching) searching.style.display = '';

    fetch('ws.php?format=json&method=' + apiMethod, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(apiParams as Record<string, string>),
    })
        .then(r => r.json())
        .then(function (raw_data: { result: { categories: CategoryResult[]; limit_reached?: boolean } }) {
            if (searching) searching.style.display = 'none';
            const categories = raw_data.result.categories;
            fill_results(categories);
            const limitReached = document.querySelector<HTMLElement>('.limitReached');
            if (limitReached) {
                if (raw_data.result.limit_reached) {
                    limitReached.innerHTML = strResultLimit.replace('%d', String(categories.length));
                } else {
                    limitReached.innerHTML = categories.length === 1
                        ? strAlbumFound
                        : strAlbumsFound.replace('%d', String(categories.length));
                }
            }
        })
        .catch(function (_e: unknown) {
            if (searching) searching.style.display = 'none';
        });
}

function fill_results(categories: CategoryResult[]): void {
    const searchResult = document.getElementById('searchResult');
    if (!searchResult) return;
    searchResult.innerHTML = '';
    categories.forEach(function (cat) {
        searchResult.insertAdjacentHTML('beforeend',
            "<div class='search-result-item' id='" + String(cat.id) + "'>"
            + "<span class='search-result-path'>" + cat.fullname + "</span>"
            + "<span class='icon-plus-circled item-add'></span></div>",
        );
    });
}

export function init(cfg: AlbumSelectorConfig): void {
    apiMethod = cfg.apiMethod ?? '';
    strNoSearchInProgress = cfg.strNoSearchInProgress ?? '';
    strAlbumsFound = cfg.strAlbumsFound ?? '';
    strAlbumFound = cfg.strAlbumFound ?? '';
    strResultLimit = cfg.strResultLimit ?? '';

    set_up_popin();
}

initModule(init as (cfg: Record<string, unknown>) => void);
