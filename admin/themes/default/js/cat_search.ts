declare var data: Array<{ id: string | number; name: string; children?: typeof data }>;

const RESULT_LIMIT = 100;
const editLink = 'admin.php?page=album-';
const colors = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];

$(function () {
    $('.limit-album-reached').hide();
    $('#cat_search_input').on('input', () => { updateSearch(); });
});

function updateSearch(): void {
    const string = String($('.search-input').val() ?? '');
    $('.search-album-result').html('');
    $('.search-album-noresult').hide();
    $('.limit-album-reached').hide();
    if (string === '') {
        $('.search-album-ghost').show();
        $('.search-album-num-result').hide();
        hideSearchContainer();
    } else {
        $('.search-album-ghost').hide();
        $('.search-album-help').hide();
        $('.search-album-num-result').show();
        showSearchContainer();
        let nbResult = searchAlbumByName(data, string, 0);
        if (nbResult !== 1) {
            if (nbResult >= RESULT_LIMIT) {
                $('.search-album-num-result').html(str_result_limit.replace('%d', String(nbResult)));
            } else {
                $('.search-album-num-result').html(str_albums_found.replace('%d', String(nbResult)));
            }
        } else {
            $('.search-album-num-result').html(str_album_found);
        }
        if (nbResult !== 0) resultAppear($('.search-album-result .search-album-elem').first());
        else $('.search-album-noresult').show();
    }
}

function searchAlbumByName(categories: typeof data, search: string, nbResult: number, name = ''): number {
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

function addAlbumResult(cat: typeof data[0], nbResult: number, haveChildren: boolean, name: string): void {
    const id = +cat.id;
    const template = $('.search-album-elem-template').html();
    const newCatNode = $(template);
    newCatNode.find('.search-album-icon').addClass(haveChildren ? 'icon-sitemap' : 'icon-folder-open');
    newCatNode.find('.search-album-icon').addClass(colors[id % 5]);
    newCatNode.find('.search-album-name').html(name.slice(0, -2));
    newCatNode.find('.search-album-edit').attr('href', 'admin.php?page=album-' + id);
    $('.search-album-result').append(newCatNode);
    if (nbResult >= RESULT_LIMIT) {
        $('.limit-album-reached').show(1000).html(str_result_limit.replace('%d', String(nbResult)));
    }
}

function resultAppear(result: JQuery): void {
    result.fadeIn();
    if (result.next().length !== 0) setTimeout(() => { resultAppear(result.next().first()); }, 50);
}

function showSearchContainer(): void {
    $('.tree').hide();
    $('.album-search-result-container').show();
}

function hideSearchContainer(): void {
    $('.album-search-result-container').hide();
    $('.tree').fadeIn();
}

export {};
