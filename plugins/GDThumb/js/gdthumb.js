import GDMasonry from './masonry.js';

export const GDThumb = {

    _initialized: false,

    setup: function (method, max_height, margin) {
        if (GDThumb._initialized) return;
        GDThumb._initialized = true;

        GDThumb.merge();
        var ul = document.querySelector("ul#thumbnails");
        if (ul) ul.classList.add("thumbnails");
        GDMasonry.init(max_height, margin);

        document.querySelectorAll("ul.thumbnails").forEach(function(list) {
            list.addEventListener("click", function(e) {
                var leg = e.target.closest(".thumbLegend.overlay, .thumbLegend.overlay-ex");
                if (!leg) return;
                var a = leg.parentElement && leg.parentElement.querySelector("a");
                if (a) window.location.href = a.getAttribute("href");
            });
        });
    },

    // Called by RVTS_CATS after it directly appends new album items.
    build: function () {
        GDMasonry.positionNew();
    },

    // Always called on setup. Handles three cases:
    //   albums + photos  → append photos into albums list, rename to #thumbnails
    //   albums only      → rename albums list to #thumbnails
    //   photos only      → already #thumbnails, nothing to do
    merge: function () {
        var albums = document.querySelector(".thumbnailCategories");
        var photos = document.querySelector("#content ul#thumbnails");

        if (albums && photos) {
            albums.insertAdjacentHTML('beforeend', photos.innerHTML);
            photos.remove();
            var loaders = document.querySelectorAll("div.loader");
            if (loaders.length > 1) loaders[1].remove();
        }

        if (albums) {
            albums.id = "thumbnails";
        }
    },
};
