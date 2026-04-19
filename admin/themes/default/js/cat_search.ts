import { initModule } from './moduleInit.js';

const RESULT_LIMIT = 100;

interface CatSearchConfig {
    catSearchNbCats?: number;
    catSearchData?: Record<string, [string, number[]]>;
    catSearchStrAlbumsFound?: string;
    catSearchStrAlbumFound?: string;
    catSearchStrResultLimit?: string;
}

export function init(cfg: CatSearchConfig): void {
    const { catSearchNbCats, catSearchData = {}, catSearchStrAlbumsFound = '', catSearchStrAlbumFound = '', catSearchStrResultLimit = '' } = cfg;

    const h1 = document.querySelector("h1");
    if (h1) h1.insertAdjacentHTML('beforeend', "<span class='badge-number'>" + (catSearchNbCats ?? 0) + "</span>");

    const categories = Object.values(catSearchData);
    const editLink = "admin.php?page=album-";
    const colors = ["icon-red", "icon-blue", "icon-yellow", "icon-purple", "icon-green"];

    const limitReachedEl = document.querySelector<HTMLElement>(".limit-album-reached");
    if (limitReachedEl) limitReachedEl.style.display = 'none';

    const searchInput = document.querySelector<HTMLInputElement>('.search-input');
    if (searchInput) searchInput.addEventListener('input', updateSearch);

    function updateSearch(): void {
        const string = searchInput?.value ?? '';
        const resultEl = document.querySelector<HTMLElement>('.search-album-result');
        const noresultEl = document.querySelector<HTMLElement>('.search-album-noresult');
        const limitEl = document.querySelector<HTMLElement>(".limit-album-reached");
        const ghostEl = document.querySelector<HTMLElement>('.search-album-ghost');
        const numResultEl = document.querySelector<HTMLElement>('.search-album-num-result');

        if (resultEl) resultEl.innerHTML = "";
        if (noresultEl) noresultEl.style.display = 'none';
        if (limitEl) limitEl.style.display = 'none';

        if (string === '') {
            if (ghostEl) ghostEl.style.display = '';
            if (numResultEl) numResultEl.style.display = 'none';
        } else {
            if (ghostEl) ghostEl.style.display = 'none';
            const helpEl = document.querySelector<HTMLElement>('.search-album-help');
            if (helpEl) helpEl.style.display = 'none';
            if (numResultEl) numResultEl.style.display = '';

            let nbResult = 0;
            categories.forEach(c => {
                if (c[0].toLowerCase().includes(string.toLowerCase()) && nbResult < RESULT_LIMIT) {
                    nbResult++;
                    addAlbumResult(c, nbResult);
                }
            });

            if (numResultEl) {
                if (nbResult !== 1) {
                    numResultEl.innerHTML = nbResult >= RESULT_LIMIT
                        ? catSearchStrResultLimit.replace('%d', String(nbResult))
                        : catSearchStrAlbumsFound.replace('%d', String(nbResult));
                } else {
                    numResultEl.innerHTML = catSearchStrAlbumFound;
                }
            }

            if (nbResult !== 0) {
                resultAppear(document.querySelector<HTMLElement>('.search-album-result .search-album-elem'));
            } else {
                if (noresultEl) noresultEl.style.display = '';
            }
        }
    }

    function addAlbumResult(cat: [string, number[]], nbResult: number): void {
        const id = cat[1][cat[1].length - 1]!;
        const templateEl = document.querySelector('.search-album-elem-template');
        const template = templateEl ? templateEl.innerHTML.trim() : '';
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = template;
        const newCatNode = tempDiv.firstElementChild as HTMLElement | null;
        if (!newCatNode) return;

        const hasChildren = categories.some(c => c[1].slice(0, -1).includes(id));

        const iconEl = newCatNode.querySelector<HTMLElement>('.search-album-icon');
        if (iconEl) {
            iconEl.classList.add(hasChildren ? 'icon-sitemap' : 'icon-folder-open');
            iconEl.classList.add(colors[id % 5]!);
        }

        const nameEl = newCatNode.querySelector('.search-album-name');
        if (nameEl) nameEl.innerHTML = getHtmlPath(cat);

        const editEl = newCatNode.querySelector('.search-album-edit');
        if (editEl) editEl.setAttribute('href', "admin.php?page=album-" + id);

        const resultEl = document.querySelector('.search-album-result');
        if (resultEl) resultEl.appendChild(newCatNode);

        if (nbResult >= RESULT_LIMIT) {
            const limitEl = document.querySelector<HTMLElement>(".limit-album-reached");
            if (limitEl) {
                limitEl.style.display = '';
                limitEl.innerHTML = catSearchStrResultLimit.replace('%d', String(nbResult));
            }
        }
    }

    function getHtmlPath(cat: [string, number[]]): string {
        let html = '';
        for (let i = 0; i < cat[1].length - 1; i++) {
            const id_bis = cat[1][i]!;
            const c = catSearchData[id_bis]!;
            html += '<a href="' + editLink + id_bis + '">' + c[0] + '</a> <b>/</b> ';
        }
        html += '<a href="' + editLink + cat[1][cat[1].length - 1]! + '">' + cat[0] + '</a>';
        return html;
    }

    function resultAppear(result: HTMLElement | null): void {
        if (!result) return;
        result.style.display = '';
        if (result.nextElementSibling) {
            setTimeout(() => resultAppear(result.nextElementSibling as HTMLElement), 50);
        }
    }

    updateSearch();
    if (searchInput) searchInput.focus();
}

initModule(init as (cfg: Record<string, unknown>) => void);
