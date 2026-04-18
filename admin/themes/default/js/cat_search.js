const _docReady = function(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); };

const RESULT_LIMIT = 100;

_docReady(function() {
    document.addEventListener('DOMContentLoaded', function() {
        var h1 = document.querySelector("h1");
        if (h1) h1.insertAdjacentHTML('beforeend', "<span class='badge-number'>"+window.cat_search_nb_cats+"</span>");
    });

    var categories = Object.values(window.cat_search_data);
    var str_albums_found = window.cat_search_str_albums_found;
    var str_album_found = window.cat_search_str_album_found;
    var str_result_limit = window.cat_search_str_result_limit;
    var editLink = "admin.php?page=album-";
    var colors = ["icon-red", "icon-blue", "icon-yellow", "icon-purple", "icon-green"];

    var limitReachedEl = document.querySelector(".limit-album-reached");
    if (limitReachedEl) limitReachedEl.style.display = 'none';

    var searchInput = document.querySelector('.search-input');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            updateSearch();
        });
    }

    function updateSearch() {
        var string = searchInput ? searchInput.value : '';
        var resultEl = document.querySelector('.search-album-result');
        var noresultEl = document.querySelector('.search-album-noresult');
        var limitEl = document.querySelector(".limit-album-reached");
        if (resultEl) resultEl.innerHTML = "";
        if (noresultEl) noresultEl.style.display = 'none';
        if (limitEl) limitEl.style.display = 'none';
        if (string == '') {
            var ghostEl = document.querySelector('.search-album-ghost');
            if (ghostEl) ghostEl.style.display = '';
            var numResultEl = document.querySelector('.search-album-num-result');
            if (numResultEl) numResultEl.style.display = 'none';
        } else {
            var ghostEl = document.querySelector('.search-album-ghost');
            if (ghostEl) ghostEl.style.display = 'none';
            var helpEl = document.querySelector('.search-album-help');
            if (helpEl) helpEl.style.display = 'none';
            var numResultEl = document.querySelector('.search-album-num-result');
            if (numResultEl) numResultEl.style.display = '';

            nbResult = 0;
            categories.forEach((c) => {
                if (c[0].toString().toLowerCase().search(string.toLowerCase()) != -1 && nbResult < RESULT_LIMIT) {
                    nbResult++;
                    addAlbumResult(c, nbResult);
                }
            });

            if (numResultEl) {
                if (nbResult != 1) {
                    if (nbResult >= RESULT_LIMIT) {
                        numResultEl.innerHTML = str_result_limit.replace('%d', nbResult);
                    } else {
                        numResultEl.innerHTML = str_albums_found.replace('%d', nbResult);
                    }
                } else {
                    numResultEl.innerHTML = str_album_found;
                }
            }

            if (nbResult != 0) {
                resultAppear(document.querySelector('.search-album-result .search-album-elem'));
            } else {
                if (noresultEl) noresultEl.style.display = '';
            }
        }
    }

    function addAlbumResult(cat, nbResult) {
        id = cat[1][cat[1].length - 1];
        var templateEl = document.querySelector('.search-album-elem-template');
        var template = templateEl ? templateEl.innerHTML.trim() : '';
        var tempDiv = document.createElement('div');
        tempDiv.innerHTML = template;
        var newCatNode = tempDiv.firstElementChild;
        if (!newCatNode) return;

        hasChildren = false;
        categories.forEach((c) => {
            for (let i = 0; i < c[1].length - 1; i++) {
                if (c[1][i] == id) {
                    hasChildren = true;
                }
            }
        });

        var iconEl = newCatNode.querySelector('.search-album-icon');
        if (iconEl) {
            iconEl.classList.add(hasChildren ? 'icon-sitemap' : 'icon-folder-open');
            colorId = id % 5;
            iconEl.classList.add(colors[colorId]);
        }

        var nameEl = newCatNode.querySelector('.search-album-name');
        if (nameEl) nameEl.innerHTML = getHtmlPath(cat);

        href = "admin.php?page=album-" + id;
        var editEl = newCatNode.querySelector('.search-album-edit');
        if (editEl) editEl.setAttribute('href', href);

        var resultEl = document.querySelector('.search-album-result');
        if (resultEl) resultEl.appendChild(newCatNode);

        if (nbResult >= RESULT_LIMIT) {
            var limitEl = document.querySelector(".limit-album-reached");
            if (limitEl) {
                limitEl.style.display = '';
                limitEl.innerHTML = str_result_limit.replace('%d', nbResult);
            }
        }
    }

    function getHtmlPath(cat) {
        html = '';
        for (let i = 0; i < cat[1].length - 1; i++) {
            id_bis = cat[1][i];
            c = window.cat_search_data[id_bis];
            html += '<a href="' + editLink + id_bis + '">' + c[0] + '</a> <b>/</b> '
        }
        html += '<a href="' + editLink + cat[1][cat[1].length - 1] + '">' + cat[0] + '</a>';
        return html
    }

    function resultAppear(result) {
        if (!result) return;
        result.style.display = '';
        if (result.nextElementSibling !== null) {
            setTimeout(() => { resultAppear(result.nextElementSibling); }, 50);
        }
    }

    updateSearch();
    if (searchInput) searchInput.focus();
});
