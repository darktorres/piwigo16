import { getPageData } from './page-data';
import { config } from './config';

interface AlbumNode {
    id: string | number;
    name: string;
    children?: AlbumNode[];
}

interface CatSearchPageData {
    data: AlbumNode[];
    str_result_limit: string;
    str_albums_found: string;
    str_album_found: string;
}

const pageData = getPageData<CatSearchPageData>();
const data = pageData.data;
const str_result_limit = pageData.str_result_limit;
const str_albums_found = pageData.str_albums_found;
const str_album_found = pageData.str_album_found;

const RESULT_LIMIT = 100;
const editLink = config.adminUrl + 'page=album-';
const colors = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll<HTMLElement>('.limit-album-reached').forEach((el) => {
        el.style.display = 'none';
    });
    document.getElementById('cat_search_input')?.addEventListener('input', () => updateSearch());
});

function updateSearch(): void {
    const string = document.querySelector<HTMLInputElement>('.search-input')?.value ?? '';
    document.querySelectorAll<HTMLElement>('.search-album-result').forEach((el) => {
        el.innerHTML = '';
    });
    document.querySelectorAll<HTMLElement>('.search-album-noresult').forEach((el) => {
        el.style.display = 'none';
    });
    document.querySelectorAll<HTMLElement>('.limit-album-reached').forEach((el) => {
        el.style.display = 'none';
    });

    if (string === '') {
        document.querySelectorAll<HTMLElement>('.search-album-ghost').forEach((el) => {
            el.style.display = '';
        });
        document.querySelectorAll<HTMLElement>('.search-album-num-result').forEach((el) => {
            el.style.display = 'none';
        });
        hideSearchContainer();
    } else {
        document.querySelectorAll<HTMLElement>('.search-album-ghost').forEach((el) => {
            el.style.display = 'none';
        });
        document.querySelectorAll<HTMLElement>('.search-album-help').forEach((el) => {
            el.style.display = 'none';
        });
        document.querySelectorAll<HTMLElement>('.search-album-num-result').forEach((el) => {
            el.style.display = '';
        });
        showSearchContainer();
        const nbResult = searchAlbumByName(data, string, 0);
        const numResult = document.querySelector<HTMLElement>('.search-album-num-result');
        if (numResult) {
            if (nbResult !== 1) {
                numResult.innerHTML =
                    nbResult >= RESULT_LIMIT
                        ? str_result_limit.replace('%d', String(nbResult))
                        : str_albums_found.replace('%d', String(nbResult));
            } else {
                numResult.innerHTML = str_album_found;
            }
        }
        if (nbResult !== 0) {
            resultAppear(
                document.querySelector<HTMLElement>('.search-album-result .search-album-elem')
            );
        } else {
            document.querySelectorAll<HTMLElement>('.search-album-noresult').forEach((el) => {
                el.style.display = '';
            });
        }
    }
}

function searchAlbumByName(
    categories: typeof data,
    search: string,
    nbResult: number,
    name = ''
): number {
    for (const c of categories) {
        if (nbResult >= RESULT_LIMIT) return nbResult;
        const currentName = name + `<a href="${editLink + String(c.id)}">${c.name}</a>` + ' / ';
        if (c.name.toString().toLowerCase().includes(search.toLowerCase())) {
            const haveChild = !!(c.children && c.children.length);
            nbResult++;
            addAlbumResult(c, nbResult, haveChild, currentName);
        }
        if (c.children && c.children.length) {
            nbResult = searchAlbumByName(c.children, search, nbResult, currentName);
        }
    }
    return nbResult;
}

function addAlbumResult(
    cat: (typeof data)[0],
    nbResult: number,
    haveChildren: boolean,
    name: string
): void {
    const id = +cat.id;
    const templateEl = document.querySelector<HTMLElement>('.search-album-elem-template');
    if (!templateEl) return;
    const div = document.createElement('div');
    div.innerHTML = templateEl.innerHTML;
    const newCatNode = div.firstElementChild as HTMLElement | null;
    if (!newCatNode) return;

    newCatNode
        .querySelector('.search-album-icon')
        ?.classList.add(haveChildren ? 'icon-sitemap' : 'icon-folder-open');
    newCatNode.querySelector('.search-album-icon')?.classList.add(colors[id % 5]);
    const nameEl = newCatNode.querySelector<HTMLElement>('.search-album-name');
    if (nameEl) nameEl.innerHTML = name.slice(0, -2);
    newCatNode
        .querySelector<HTMLAnchorElement>('.search-album-edit')
        ?.setAttribute('href', config.adminUrl + 'page=album-' + id);

    document.querySelector('.search-album-result')?.append(newCatNode);

    if (nbResult >= RESULT_LIMIT) {
        document.querySelectorAll<HTMLElement>('.limit-album-reached').forEach((el) => {
            el.style.display = '';
            el.innerHTML = str_result_limit.replace('%d', String(nbResult));
        });
    }
}

function resultAppear(result: HTMLElement | null): void {
    if (!result) return;
    result.style.display = '';
    const next = result.nextElementSibling as HTMLElement | null;
    if (next) setTimeout(() => resultAppear(next), 50);
}

function showSearchContainer(): void {
    document.querySelectorAll<HTMLElement>('.tree').forEach((el) => {
        el.style.display = 'none';
    });
    document.querySelectorAll<HTMLElement>('.album-search-result-container').forEach((el) => {
        el.style.display = '';
    });
}

function hideSearchContainer(): void {
    document.querySelectorAll<HTMLElement>('.album-search-result-container').forEach((el) => {
        el.style.display = 'none';
    });
    document.querySelectorAll<HTMLElement>('.tree').forEach((el) => {
        el.style.display = '';
    });
}

export {};
