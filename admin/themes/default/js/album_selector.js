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
    if (limitReached) limitReached.innerHTML = str_no_search_in_progress;
}

export function linked_albums_search(searchText) {
    if (api_method == "pwg.categories.getList") {
        api_params = {
            cat_id: 0,
            recursive: true,
            fullname: true,
            search: searchText,
        };
    } else {
        api_params = {
            search: searchText,
            additional_output: "full_name_with_admin_links",
        };
    }

    console.log("lalalal");
    console.log(api_method);

    var searching = document.querySelector(".linkedAlbumPopInContainer .searching");
    if (searching) searching.style.display = '';

    var params = new URLSearchParams(api_params);
    fetch("ws.php?format=json&method=" + api_method, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: params,
    })
        .then(function (response) { return response.json(); })
        .then(function (raw_data) {
            if (searching) searching.style.display = 'none';

            categories = raw_data.result.categories;
            fill_results(categories);

            var limitReached = document.querySelector(".limitReached");
            if (limitReached) {
                if (raw_data.result.limit_reached) {
                    limitReached.innerHTML = str_result_limit.replace("%d", categories.length);
                } else {
                    if (categories.length == 1) {
                        limitReached.innerHTML = str_album_found;
                    } else {
                        limitReached.innerHTML = str_albums_found.replace("%d", categories.length);
                    }
                }
            }
        })
        .catch(function (e) {
            if (searching) searching.style.display = 'none';
            console.log(e.message);
        });
}
