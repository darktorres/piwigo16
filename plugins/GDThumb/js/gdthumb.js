var GDThumb = {
    max_height: 200,
    margin: 10,
    max_first_thumb_width: 0.7,
    big_thumb: null,
    big_thumb_block: false,
    check_pv: false,
    small_thumb: null,
    method: "crop",
    t: new Array(),
    do_merge: false,

    // Initialize plugin logic, perform necessary steps
    setup: function (
        method,
        max_height,
        margin,
        do_merge,
        big_thumb,
        check_pv,
    ) {
        jQuery("ul#thumbnails").addClass("thumbnails");

        GDThumb.max_height = max_height;
        GDThumb.margin = margin;
        GDThumb.method = method;
        GDThumb.check_pv = check_pv;
        GDThumb.do_merge = do_merge;
        GDThumb.big_thumb = big_thumb;

        $(window).bind("RVTS_loaded", function () {
            GDThumb.init();
        });
        GDThumb.init();
    },

    init: function () {
        var mainlists = jQuery("ul.thumbnails");
        if (typeof mainlists !== "undefined") {
            if (GDThumb.do_merge) {
                GDThumb.merge();
            }

            GDThumb.build();
            jQuery(window).bind("RVTS_loaded", GDThumb.build);

            mainlists.resize(GDThumb.process);
            jQuery("ul.thumbnails .thumbLegend.overlay").click(function () {
                window.location.href = $(this).parent().find("a").attr("href");
            });
            jQuery("ul.thumbnails .thumbLegend.overlay-ex").click(function () {
                window.location.href = $(this).parent().find("a").attr("href");
            });
        }
    },

    // Merge categories and picture lists
    merge: function () {
        var mainlists = $(".content ul.thumbnails");
        if (mainlists.length < 2) {
            // there is only one list of elements
        } else {
            $(".thumbnailCategories li").addClass("album");
            $(".thumbnailCategories").append(
                $(".content ul#thumbnails").html(),
            );
            $("ul#thumbnails").remove();
            $("div.loader:eq(1)").remove();
        }
    },

    // Build thumb metadata
    build: function () {
        if (
            GDThumb.method == "square" &&
            GDThumb.big_thumb != null &&
            (GDThumb.big_thumb.height != GDThumb.big_thumb.width ||
                GDThumb.big_thumb.height < GDThumb.max_height)
        ) {
            var main_width = jQuery("ul.thumbnails").width();
            var max_col_count = Math.floor(main_width / GDThumb.max_height);
            var thumb_width =
                Math.floor(main_width / max_col_count) - GDThumb.margin;

            GDThumb.big_thumb.height = thumb_width * 2 + GDThumb.margin;
            GDThumb.big_thumb.width = GDThumb.big_thumb.height;
            GDThumb.big_thumb.crop = GDThumb.big_thumb.height;
            GDThumb.max_height = thumb_width;
        } else if (GDThumb.method == "slide") {
            var main_width = jQuery("ul.thumbnails").width();
            var max_col_count = Math.floor(main_width / GDThumb.max_height);
            var thumb_width =
                Math.floor(main_width / max_col_count) - GDThumb.margin;
            GDThumb.max_height = thumb_width;
        }

        GDThumb.t = new Array();
        $("ul.thumbnails img.thumbnail").each(function (index) {
            width = parseInt(jQuery(this).attr("width"));
            height = parseInt(jQuery(this).attr("height"));
            th = {
                index: index,
                width: width,
                height: height,
                real_width: width,
                real_height: height,
            };

            if (GDThumb.check_pv) {
                var ratio = th.width / th.height;
                GDThumb.big_thumb_block = ratio > 2.2 || ratio < 0.455;
            }

            if (
                (GDThumb.method == "square" || GDThumb.method == "slide") &&
                th.height != th.width
            ) {
                th.width = GDThumb.max_height;
                th.height = GDThumb.max_height;
                th.crop = GDThumb.max_height;
            } else if (height < GDThumb.max_height) {
                th.width = Math.round((GDThumb.max_height * width) / height);
                th.height = GDThumb.max_height;
            }

            GDThumb.t.push(th);
        });

        if (GDThumb.big_thumb_block) {
            GDThumb.big_thumb = null;
        }

        first = GDThumb.t[0];
        if (first) {
            GDThumb.small_thumb = {
                index: first.index,
                width: first.real_width,
                height: first.real_height,
                src: jQuery("ul.thumbnails img.thumbnail:first").attr("src"),
            };
        }
        jQuery.resize.throttleWindow = false;
        jQuery.resize.delay = 50;
        GDThumb.process();
    },

    // Adjust thumb attributes to match plugin settings
    process: function () {
        var width_count = GDThumb.margin;
        var line = 1;
        var round_rest = 0;
        var main_width = jQuery("ul.thumbnails").width();
        var first_thumb = jQuery("ul.thumbnails img.thumbnail:first");
        var best_size = { width: 1, height: 1 };

        if (GDThumb.method == "slide") {
            best_size.width = GDThumb.max_height;
            best_size.height = GDThumb.max_height;

            GDThumb.resize(
                first_thumb,
                GDThumb.t[0].real_width,
                GDThumb.t[0].real_height,
                GDThumb.t[0].width,
                GDThumb.t[0].height,
                false,
            );
        } else if (GDThumb.method == "square") {
            if (GDThumb.big_thumb != null) {
                best_size.width = GDThumb.big_thumb.width;
                best_size.height = GDThumb.big_thumb.height;

                if (GDThumb.big_thumb.src != first_thumb.attr("src")) {
                    first_thumb.attr("src", GDThumb.big_thumb.src).attr({
                        width: GDThumb.big_thumb.width,
                        height: GDThumb.big_thumb.height,
                    });
                    GDThumb.t[0].width = GDThumb.big_thumb.width;
                    GDThumb.t[0].height = GDThumb.big_thumb.height;
                }
                GDThumb.t[0].crop = best_size.width;
                GDThumb.resize(
                    first_thumb,
                    GDThumb.t[0].real_width,
                    GDThumb.t[0].real_height,
                    GDThumb.big_thumb.width,
                    GDThumb.big_thumb.height,
                    true,
                );
            } else {
                best_size.width = GDThumb.max_height;
                best_size.height = GDThumb.max_height;
                GDThumb.resize(
                    first_thumb,
                    GDThumb.t[0].real_width,
                    GDThumb.t[0].real_height,
                    GDThumb.t[0].width,
                    GDThumb.t[0].height,
                    true,
                );
            }
        } else {
            if (
                GDThumb.big_thumb != null &&
                GDThumb.big_thumb.height <
                    main_width * GDThumb.max_first_thumb_width
            ) {
                // Compute best size for landscape picture (we choose bigger height)
                min_ratio = Math.min(
                    1.05,
                    GDThumb.big_thumb.width / GDThumb.big_thumb.height,
                );

                for (
                    width = GDThumb.big_thumb.width;
                    width / best_size.height >= min_ratio;
                    width--
                ) {
                    width_count = GDThumb.margin;
                    height = GDThumb.margin;
                    max_height = 0;
                    available_width = main_width - (width + GDThumb.margin);
                    line = 1;
                    for (i = 1; i < GDThumb.t.length; i++) {
                        width_count += GDThumb.t[i].width + GDThumb.margin;
                        max_height = Math.max(GDThumb.t[i].height, max_height);

                        if (width_count > available_width) {
                            ratio = width_count / available_width;
                            height += Math.round(max_height / ratio);
                            line++;
                            max_height = 0;
                            width_count = GDThumb.margin;
                            if (line > 2) {
                                if (
                                    height >= best_size.height &&
                                    width / height >= min_ratio &&
                                    height <= GDThumb.big_thumb.height
                                ) {
                                    best_size = {
                                        width: width,
                                        height: height,
                                    };
                                }
                                break;
                            }
                        }
                    }
                    if (line <= 2) {
                        if (max_height == 0 || line == 1) {
                            height = GDThumb.big_thumb.height;
                        } else {
                            height += max_height;
                        }
                        if (
                            height >= best_size.height &&
                            width / height >= min_ratio &&
                            height <= GDThumb.big_thumb.height
                        ) {
                            best_size = { width: width, height: height };
                        }
                    }
                }
                if (GDThumb.big_thumb.src != first_thumb.attr("src")) {
                    first_thumb.attr("src", GDThumb.big_thumb.src).attr({
                        width: GDThumb.big_thumb.width,
                        height: GDThumb.big_thumb.height,
                    });
                    GDThumb.t[0].width = GDThumb.big_thumb.width;
                    GDThumb.t[0].height = GDThumb.big_thumb.height;
                }
                GDThumb.t[0].crop = best_size.width;
                GDThumb.resize(
                    first_thumb,
                    GDThumb.big_thumb.width,
                    GDThumb.big_thumb.height,
                    best_size.width,
                    best_size.height,
                    true,
                );
            }
        }

        if (best_size.width == 1) {
            if (
                GDThumb.small_thumb != null &&
                GDThumb.small_thumb.src != first_thumb.attr("src")
            ) {
                first_thumb.prop("src", GDThumb.small_thumb.src).attr({
                    width: GDThumb.small_thumb.width,
                    height: GDThumb.small_thumb.height,
                });
                GDThumb.t[0].width = GDThumb.small_thumb.width;
                GDThumb.t[0].height = GDThumb.small_thumb.height;
            }
            GDThumb.t[0].crop = false;
        }

        width_count = GDThumb.margin;
        max_height = 0;
        last_height = GDThumb.max_height;
        line = 1;
        thumb_process = new Array();

        for (
            i = GDThumb.t[0].crop != false ? 1 : 0;
            i < GDThumb.t.length;
            i++
        ) {
            width_count += GDThumb.t[i].width + GDThumb.margin;
            max_height = Math.max(GDThumb.t[i].height, max_height);
            thumb_process.push(GDThumb.t[i]);

            available_width = main_width;
            if (line <= 2 && GDThumb.t[0].crop !== false) {
                available_width -= GDThumb.t[0].crop + GDThumb.margin;
            }

            if (width_count > available_width) {
                last_thumb = GDThumb.t[i].index;
                ratio = width_count / available_width;
                new_height = Math.round(max_height / ratio);
                round_rest = 0;
                width_count = GDThumb.margin;

                for (j = 0; j < thumb_process.length; j++) {
                    if (
                        GDThumb.method == "square" ||
                        GDThumb.method == "slide"
                    ) {
                        new_width = GDThumb.max_height;
                        new_height = GDThumb.max_height;
                    } else {
                        if (thumb_process[j].index == last_thumb) {
                            new_width =
                                available_width - width_count - GDThumb.margin;
                        } else {
                            new_width =
                                (thumb_process[j].width + round_rest) / ratio;
                            round_rest = new_width - Math.round(new_width);
                            new_width = Math.round(new_width);
                        }
                    }
                    GDThumb.resize(
                        jQuery("ul.thumbnails img.thumbnail").eq(
                            thumb_process[j].index,
                        ),
                        thumb_process[j].real_width,
                        thumb_process[j].real_height,
                        new_width,
                        new_height,
                        false,
                    );
                    last_height = Math.min(last_height, new_height);

                    width_count += new_width + GDThumb.margin;
                }
                thumb_process = new Array();
                width_count = GDThumb.margin;
                max_height = 0;
                line++;
            }
        }

        if (last_height == 0) {
            last_height = GDThumb.max_height;
        }

        // Crop last line only if we have more than one line
        for (j = 0; j < thumb_process.length; j++) {
            // we have only one line, i.e. the first line is the one and only line and therefor the last line too
            if (line == 1) {
                GDThumb.resize(
                    jQuery("ul.thumbnails img.thumbnail").eq(
                        thumb_process[j].index,
                    ),
                    thumb_process[j].real_width,
                    thumb_process[j].real_height,
                    thumb_process[j].width,
                    last_height,
                    false,
                );
            }
            // we have more than one line
            else {
                if (GDThumb.method == "square" || GDThumb.method == "slide") {
                    new_width = GDThumb.max_height;
                    new_height = GDThumb.max_height;
                } else {
                    new_width = (thumb_process[j].width + round_rest) / ratio;
                    round_rest = new_width - Math.round(new_width);
                    new_width = Math.round(new_width);
                }

                GDThumb.resize(
                    jQuery("ul.thumbnails img.thumbnail").eq(
                        thumb_process[j].index,
                    ),
                    thumb_process[j].real_width,
                    thumb_process[j].real_height,
                    new_width,
                    new_height,
                    false,
                );
                last_height = Math.min(last_height, new_height);

                width_count += new_width + GDThumb.margin;
            }
        }

        if (main_width != jQuery("ul.thumbnails").width()) {
            GDThumb.process();
        }
    },

    resize: function (thumb, width, height, new_width, new_height, is_big) {
        use_crop = true;
        if (GDThumb.method == "slide") {
            use_crop = false;
            thumb.css({ height: "", width: "" });
            new_width = new_height;

            if (width < height) {
                real_height = Math.round((height * new_width) / width);
                real_width = new_width;
            } else {
                real_height = new_width;
                real_width = Math.round((width * new_height) / height);
            }

            height_crop = Math.round((real_height - new_height) / 2);
            width_crop = Math.round((real_width - new_height) / 2);
            thumb.css({
                height: real_height + "px",
                width: real_width + "px",
            });
        } else if (!is_big && GDThumb.method == "square") {
            thumb.css({ height: "", width: "" });
            new_width = new_height;

            if (width < height) {
                real_height = Math.round((height * new_width) / width);
                real_width = new_width;
            } else {
                real_height = new_width;
                real_width = Math.round((width * new_height) / height);
            }

            height_crop = Math.round((real_height - new_height) / 2);
            width_crop = Math.round((real_width - new_width) / 2);
            thumb.css({
                height: real_height + "px",
                width: real_width + "px",
            });
        } else if (
            GDThumb.method == "resize" ||
            height < new_height ||
            width < new_width
        ) {
            real_width = new_width;
            real_height = new_height;
            width_crop = 0;
            height_crop = 0;

            if (is_big) {
                if (width - new_width > height - new_height) {
                    real_width = Math.round((new_height * width) / height);
                    width_crop = Math.round((real_width - new_width) / 2);
                } else {
                    real_height = Math.round((new_width * height) / width);
                    height_crop = Math.round((real_height - new_height) / 2);
                }
            }
            thumb.css({
                height: real_height + "px",
                width: real_width + "px",
            });
        } else {
            thumb.css({ height: "", width: "" });
            height_crop = Math.round((height - new_height) / 2);
            width_crop = Math.round((width - new_width) / 2);
        }

        thumb
            .parents("li")
            .css({ height: new_height + "px", width: new_width + "px" });
        if (use_crop) {
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
        } else {
            thumb
                .parent("a")
                .css({ top: -height_crop + "px", left: -width_crop + "px" });
        }
    },
};
