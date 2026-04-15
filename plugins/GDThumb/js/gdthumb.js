var GDThumb = {

    setup: function (method, max_height, margin) {
        GDThumb.merge();
        jQuery("ul#thumbnails").addClass("thumbnails");
        GDMasonry.init(300, 4);

        jQuery("ul.thumbnails").on("click", ".thumbLegend.overlay", function () {
            window.location.href = $(this).parent().find("a").attr("href");
        });
        jQuery("ul.thumbnails").on("click", ".thumbLegend.overlay-ex", function () {
            window.location.href = $(this).parent().find("a").attr("href");
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
        var $albums = $(".thumbnailCategories");
        var $photos = $("#content ul#thumbnails");

        if ($albums.length && $photos.length) {
            $albums.append($photos.html());
            $photos.remove();
            $("div.loader:eq(1)").remove();
        }

        if ($albums.length) {
            $albums.attr("id", "thumbnails");
        }
    },
};
