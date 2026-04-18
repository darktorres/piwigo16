import { initModule } from './moduleInit.js';

let apiMethod, strNoSearchInProgress, strAlbumsFound, strAlbumFound, strResultLimit;

export function set_up_popin() {
    document.querySelectorAll(".ClosePopIn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            linked_albums_close();
        });
    });
}

export function linked_albums_close() {
    var addLinkedAlbum = document.getElementById("addLinkedAlbum");
    if (addLinkedAlbum) addLinkedAlbum.style.display = 'none';
}

export function linked_albums_open() {
    var addLinkedAlbum = document.getElementById("addLinkedAlbum");
    if (addLinkedAlbum) addLinkedAlbum.style.display = '';
    var searchInput = document.querySelector(".search-input");
    if (searchInput) {
        searchInput.value = "";
        searchInput.focus();
    }
    var searchResult = document.getElementById("searchResult");
    if (searchResult) searchResult.innerHTML = '';
    var limitReached = document.querySelector(".limitReached");
    if (limitReached) limitReached.innerHTML = strNoSearchInProgress;
}

export function linked_albums_search(searchText) {
    let apiParams;
    if (apiMethod == "pwg.categories.getList") {
        apiParams = {
            cat_id: 0,
            recursive: true,
            fullname: true,
            search: searchText,
        };
    } else {
        apiParams = {
            search: searchText,
            additional_output: "full_name_with_admin_links",
        };
    }

    var searching = document.querySelector(".linkedAlbumPopInContainer .searching");
    if (searching) searching.style.display = '';

    var params = new URLSearchParams(apiParams);
    fetch("ws.php?format=json&method=" + apiMethod, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: params,
    })
        .then(function (response) { return response.json(); })
        .then(function (raw_data) {
            if (searching) searching.style.display = 'none';

            const categories = raw_data.result.categories;
            fill_results(categories);

            var limitReached = document.querySelector(".limitReached");
            if (limitReached) {
                if (raw_data.result.limit_reached) {
                    limitReached.innerHTML = strResultLimit.replace("%d", categories.length);
                } else {
                    if (categories.length == 1) {
                        limitReached.innerHTML = strAlbumFound;
                    } else {
                        limitReached.innerHTML = strAlbumsFound.replace("%d", categories.length);
                    }
                }
            }
        })
        .catch(function (_e) {
            if (searching) searching.style.display = 'none';
        });
}

function fill_results(categories) {
    var searchResult = document.getElementById("searchResult");
    if (!searchResult) return;
    searchResult.innerHTML = '';
    categories.forEach(function (cat) {
        searchResult.insertAdjacentHTML(
            "beforeend",
            "<div class='search-result-item' id='" +
                cat.id +
                "'>" +
                "<span class='search-result-path'>" +
                cat.fullname +
                "</span><span class='icon-plus-circled item-add'></span>" +
                "</div>"
        );
    });
}

export function init(cfg) {
    apiMethod = cfg.apiMethod;
    strNoSearchInProgress = cfg.strNoSearchInProgress;
    strAlbumsFound = cfg.strAlbumsFound;
    strAlbumFound = cfg.strAlbumFound;
    strResultLimit = cfg.strResultLimit;

    set_up_popin();
}

initModule(init);
