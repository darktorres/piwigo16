var GDThumb = {
    max_height: 200,
    margin: 10,
    method: "crop",
    t: [],
    do_merge: false,
    _$list: null,
    _$imgs: null,
    _processing: false,

    // Initialize plugin logic, perform necessary steps
    setup: function (method, max_height, margin, do_merge) {
        jQuery("ul#thumbnails").addClass("thumbnails");

        GDThumb.max_height = max_height;
        GDThumb.margin = margin;
        GDThumb.method = method;
        GDThumb.do_merge = do_merge;

        GDThumb.init();
    },

    init: function () {
        var mainlists = jQuery("ul.thumbnails");
        if (mainlists.length > 0) {
            if (GDThumb.do_merge) {
                GDThumb.merge();
            }

            GDThumb.build();

            mainlists.resize(GDThumb.process);
            jQuery("ul.thumbnails").on("click", ".thumbLegend.overlay", function () {
                window.location.href = $(this).parent().find("a").attr("href");
            });
            jQuery("ul.thumbnails").on("click", ".thumbLegend.overlay-ex", function () {
                window.location.href = $(this).parent().find("a").attr("href");
            });
        }
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

    // Build thumb metadata
    build: function () {
        GDThumb._$list = jQuery("ul.thumbnails");
        GDThumb._$imgs = GDThumb._$list.find("img.thumbnail");

        GDThumb.t = [];
        GDThumb._$imgs.each(function (index) {
            var width = parseInt(jQuery(this).attr("width"));
            var height = parseInt(jQuery(this).attr("height"));
            var th = {
                index: index,
                width: width,
                height: height,
                real_width: width,
                real_height: height,
            };

            if (height < GDThumb.max_height) {
                th.width = Math.round((GDThumb.max_height * width) / height);
                th.height = GDThumb.max_height;
            }

            GDThumb.t.push(th);
        });

        jQuery.resize.throttleWindow = false;
        jQuery.resize.delay = 50;
        GDThumb.process();
    },

    // Adjust thumb attributes to match plugin settings
    process: function () {
        var width_count = GDThumb.margin;
        var line = 1;
        var round_rest = 0;
        var ratio;
        var main_width = GDThumb._$list.width();
        var max_height = 0;
        var last_height = GDThumb.max_height;
        var thumb_process = [];

        for (var i = 0; i < GDThumb.t.length; i++) {
            width_count += GDThumb.t[i].width + GDThumb.margin;
            max_height = Math.max(GDThumb.t[i].height, max_height);
            thumb_process.push(GDThumb.t[i]);

            if (width_count > main_width) {
                var last_thumb = GDThumb.t[i].index;
                ratio = width_count / main_width;
                var new_height = Math.round(max_height / ratio);
                round_rest = 0;
                width_count = GDThumb.margin;

                for (var j = 0; j < thumb_process.length; j++) {
                    var new_width;
                    if (thumb_process[j].index == last_thumb) {
                        new_width = main_width - width_count - GDThumb.margin;
                    } else {
                        new_width = (thumb_process[j].width + round_rest) / ratio;
                        round_rest = new_width - Math.round(new_width);
                        new_width = Math.round(new_width);
                    }
                    GDThumb.resize(
                        GDThumb._$imgs.eq(thumb_process[j].index),
                        thumb_process[j].real_width,
                        thumb_process[j].real_height,
                        new_width,
                        new_height,
                    );
                    last_height = Math.min(last_height, new_height);
                    width_count += new_width + GDThumb.margin;
                }
                thumb_process = [];
                width_count = GDThumb.margin;
                max_height = 0;
                line++;
            }
        }

        if (last_height == 0) {
            last_height = GDThumb.max_height;
        }

        // Last (partial) line
        for (var j = 0; j < thumb_process.length; j++) {
            if (line == 1) {
                // only one line total — use natural sizes
                GDThumb.resize(
                    GDThumb._$imgs.eq(thumb_process[j].index),
                    thumb_process[j].real_width,
                    thumb_process[j].real_height,
                    thumb_process[j].width,
                    last_height,
                );
            } else {
                var new_width = (thumb_process[j].width + round_rest) / ratio;
                round_rest = new_width - Math.round(new_width);
                new_width = Math.round(new_width);
                GDThumb.resize(
                    GDThumb._$imgs.eq(thumb_process[j].index),
                    thumb_process[j].real_width,
                    thumb_process[j].real_height,
                    new_width,
                    last_height,
                );
            }
        }

        if (main_width != GDThumb._$list.width() && !GDThumb._processing) {
            GDThumb._processing = true;
            GDThumb.process();
            GDThumb._processing = false;
        }
    },

    resize: function (thumb, width, height, new_width, new_height) {
        var height_crop, width_crop;

        if (GDThumb.method == "resize" || height < new_height || width < new_width) {
            height_crop = 0;
            width_crop = 0;
            thumb.css({
                height: new_height + "px",
                width: new_width + "px",
            });
        } else {
            thumb.css({ height: "", width: "" });
            height_crop = Math.round((height - new_height) / 2);
            width_crop = Math.round((width - new_width) / 2);
        }

        thumb
            .parents("li")
            .css({ height: new_height + "px", width: new_width + "px" });
        thumb.parent("a").css({
            clip:
                "rect(" +
                height_crop +
                "px, " +
                (new_width + width_crop) +
                "px, " +
                (new_height + height_crop) +
                "px, " +
                width_crop +
                "px)",
            top: -height_crop + "px",
            left: -width_crop + "px",
        });
    },
};
