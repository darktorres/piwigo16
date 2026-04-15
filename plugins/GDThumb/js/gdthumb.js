var GDThumb = {
    do_merge: false,

    setup: function (method, max_height, margin, do_merge) {
        jQuery("ul#thumbnails").addClass("thumbnails");
        GDThumb.do_merge = do_merge;

        if (do_merge) {
            GDThumb.merge();
        }

        jQuery("ul.thumbnails").on("click", ".thumbLegend.overlay", function () {
            window.location.href = $(this).parent().find("a").attr("href");
        });
        jQuery("ul.thumbnails").on("click", ".thumbLegend.overlay-ex", function () {
            window.location.href = $(this).parent().find("a").attr("href");
        });
    },

    // Merge categories and picture lists
    merge: function () {
        var mainlists = $("#content ul.thumbnails");
        if (mainlists.length < 2) {
            // there is only one list of elements
        } else {
            $(".thumbnailCategories li").addClass("album");
            $(".thumbnailCategories").append(
                $("#content ul#thumbnails").html(),
            );
            $("ul#thumbnails").remove();
            $(".thumbnailCategories").attr("id", "thumbnails");
            $("div.loader:eq(1)").remove();
        }
    },
};
