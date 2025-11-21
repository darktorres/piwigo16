/**
 * GDThumb - Responsive thumbnail gallery layout system
 * Dynamically resizes thumbnails to fill width while maintaining aspect ratio
 * @namespace GDThumb
 * @typedef {Object} ThumbnailData
 * @property {number} index - Index in the thumbnail list
 * @property {number} width - Current display width in pixels
 * @property {number} height - Current display height in pixels
 * @property {number} real_width - Original image width
 * @property {number} real_height - Original image height
 */
var GDThumb = {
    /** @type {boolean} - Enable side-by-side jQuery vs vanilla JS validation */
    _validateMode: false,

    /**
     * Validate jQuery and vanilla JS produce same results
     * @private
     */
    _validate: function(name, jqueryFn, vanillaFn) {
        if (!this._validateMode) return;
        var jqResult = jqueryFn();
        var vjResult = vanillaFn();
        var match = JSON.stringify(jqResult) === JSON.stringify(vjResult);
        console.log("[VALIDATE] " + name + ":", {
            jQuery: jqResult,
            vanilla: vjResult,
            match: match ? "✓ PASS" : "✗ FAIL"
        });
        return match;
    },

    /** @type {number} - Maximum height for thumbnails (pixels) */
    max_height: 200,
    /** @type {number} - Margin between thumbnails (pixels) */
    margin: 10,
    /** @type {number} - Maximum first thumbnail width relative to container */
    max_first_thumb_width: 0.7,
    /** @type {Object|null} - Big representative thumbnail configuration */
    big_thumb: null,
    /** @type {boolean} - Whether big thumbnail is blocked */
    big_thumb_block: false,
    /** @type {boolean} - Check preview images */
    check_pv: false,
    /** @type {Object|null} - Small fallback thumbnail */
    small_thumb: null,
    /** @type {string} - Layout method: 'crop', 'slide', or 'square' */
    method: "crop",
    /** @type {Array<ThumbnailData>} - Array of thumbnail data */
    t: new Array(),
    /** @type {boolean} - Whether to merge category and thumbnail displays */
    do_merge: false,
    /** @type {boolean} - Internal: whether plugin has been initialized */
    _initialized: false,
    /** @type {boolean} - Internal: whether resize observer is attached */
    _resizeObserverAttached: false,
    /** @type {number} - Internal: recursion depth counter for process() */
    _processDepth: 0,
    /** @type {Function|null} - Internal: stored click handler for cleanup */
    _clickHandler: null,

    /**
     * Initialize plugin with configuration
     * @param {string} method - Layout method ('crop', 'slide', 'square')
     * @param {number} max_height - Maximum thumbnail height in pixels
     * @param {number} margin - Margin between thumbnails in pixels
     * @param {boolean} do_merge - Whether to merge category and thumbnail displays
     * @param {Object|null} big_thumb - Big representative thumbnail config
     * @param {boolean} check_pv - Check preview images flag
     * @returns {void}
     */
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

        // Register handler once and only once
        if (!GDThumb._initialized) {
            window.addEventListener("RVTS_loaded", function () {
                GDThumb.init();
            });
            GDThumb._initialized = true;
        }

        GDThumb.init();
    },

    /**
     * Initialize the gallery after page load or AJAX content added
     * Reads thumbnail sizes, attaches resize observer, and processes layout
     * @returns {void}
     */
    init: function () {
        var thumbnailList = document.querySelector("ul.thumbnails");
        if (thumbnailList) {
            if (GDThumb.do_merge) {
                GDThumb.merge();
            }

            GDThumb.build();

            // Only attach resize observer once
            if (!GDThumb._resizeObserverAttached) {
                if (thumbnailList && window.ResizeObserver) {
                    var resizeObserver = new ResizeObserver(function() {
                        GDThumb.process();
                    });
                    resizeObserver.observe(thumbnailList);
                    GDThumb._resizeObserverAttached = true;
                }
            }

            // Re-attach click handlers with event delegation (needed for new thumbnails)
            var clickHandler = function(e) {
                var target = e.target;
                var legend = target.closest(".thumbLegend.overlay, .thumbLegend.overlay-ex");
                if (legend) {
                    var link = legend.closest("li").querySelector("a");
                    if (link) {
                        window.location.href = link.getAttribute("href");
                    }
                }
            };

            // Remove old handler if exists and attach new one
            thumbnailList.removeEventListener("click", GDThumb._clickHandler);
            thumbnailList.addEventListener("click", clickHandler);
            GDThumb._clickHandler = clickHandler;
        }
    },

    /**
     * Merge category thumbnails with regular thumbnail list
     * Combines both lists into single display when applicable
     * @returns {void}
     */
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

    /**
     * Read thumbnail DOM elements and build metadata array
     * Extracts dimensions from image tags for layout calculations
     * @returns {void}
     */
    build: function () {
        var thumbnailsList = document.querySelector("ul.thumbnails");
        var main_width = thumbnailsList ? thumbnailsList.clientWidth : 0;

        if (
            GDThumb.method == "square" &&
            GDThumb.big_thumb != null &&
            (GDThumb.big_thumb.height != GDThumb.big_thumb.width ||
                GDThumb.big_thumb.height < GDThumb.max_height)
        ) {
            var max_col_count = Math.floor(main_width / GDThumb.max_height);
            var thumb_width =
                Math.floor(main_width / max_col_count) - GDThumb.margin;

            GDThumb.big_thumb.height = thumb_width * 2 + GDThumb.margin;
            GDThumb.big_thumb.width = GDThumb.big_thumb.height;
            GDThumb.big_thumb.crop = GDThumb.big_thumb.height;
            GDThumb.max_height = thumb_width;
        } else if (GDThumb.method == "slide") {
            var max_col_count = Math.floor(main_width / GDThumb.max_height);
            var thumb_width =
                Math.floor(main_width / max_col_count) - GDThumb.margin;
            GDThumb.max_height = thumb_width;
        }

        // Reuse array instead of creating new one
        if (GDThumb.t) {
            GDThumb.t.length = 0;
        } else {
            GDThumb.t = new Array();
        }

        // Vanilla JS version with forEach
        var thumbnailImages = document.querySelectorAll("ul.thumbnails img.thumbnail");
        thumbnailImages.forEach(function (img, index) {
            var width = parseInt(img.getAttribute("width"), 10);
            var height = parseInt(img.getAttribute("height"), 10);
            var th = {
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

        var first = GDThumb.t[0];
        if (first) {
            GDThumb.small_thumb = {
                index: first.index,
                width: first.real_width,
                height: first.real_height,
                src: jQuery("ul.thumbnails img.thumbnail:first").attr("src"),
            };
        }
        GDThumb.process();
    },

    /**
     * Find where the last rendered row starts by scanning DOM positions
     * Used for incremental layout calculation on AJAX loads
     * @returns {number} Index of first image in last row
     * @private
     */
    _findLastRowStartIndex: function () {
        var $images = jQuery("ul.thumbnails img.thumbnail");

        if ($images.length === 0) {
            return 0;
        }

        // Get the top position of the last image
        var lastImageTop = $images.last().position().top;

        // Scan backwards to find where this row started
        for (var i = $images.length - 2; i >= 0; i--) {
            if ($images.eq(i).position().top !== lastImageTop) {
                return i + 1; // Found it - this is where the last row starts
            }
        }

        return 0; // All images in one row
    },

    /**
     * Calculate optimal thumbnail dimensions and apply to DOM
     * Main algorithm: fills rows pixel-by-pixel to fit available width while maintaining aspect ratios
     * On initial load: recalculates from beginning
     * On AJAX load: starts from last row for incremental update
     * @returns {void}
     */
    process: function () {
        var thumbnailsList = document.querySelector("ul.thumbnails");
        var main_width = thumbnailsList ? thumbnailsList.clientWidth : 0;
        var $allThumbs = jQuery("ul.thumbnails img.thumbnail");

        var width_count = GDThumb.margin;
        var max_height = 0;
        var last_height = GDThumb.max_height;
        var line = 1;
        var thumb_process;
        // Reuse array instead of creating new one
        if (!thumb_process) {
            thumb_process = new Array();
        } else {
            thumb_process.length = 0;
        }

        // On AJAX loads, start from the last row instead of recalculating everything
        var startIndex = GDThumb._findLastRowStartIndex();

        for (
            var i = startIndex;
            i < GDThumb.t.length;
            i++
        ) {
            width_count += GDThumb.t[i].width + GDThumb.margin;
            max_height = Math.max(GDThumb.t[i].height, max_height);
            thumb_process.push(GDThumb.t[i]);

            var available_width = main_width;

            if (width_count > available_width) {
                var last_thumb = GDThumb.t[i].index;
                var ratio = width_count / available_width;
                var new_height = Math.round(max_height / ratio);
                var round_rest = 0;
                width_count = GDThumb.margin;

                for (var j = 0; j < thumb_process.length; j++) {
                    var new_width;
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
                        $allThumbs.eq(
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
                // Reuse array instead of creating new one
                thumb_process.length = 0;
                width_count = GDThumb.margin;
                max_height = 0;
                line++;
            }
        }

        if (last_height == 0) {
            last_height = GDThumb.max_height;
        }

        // Crop last line only if we have more than one line
        for (var j = 0; j < thumb_process.length; j++) {
            var new_width;
            // we have only one line, i.e. the first line is the one and only line and therefor the last line too
            if (line == 1) {
                GDThumb.resize(
                    $allThumbs.eq(
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

        var currentWidth = thumbnailsList ? thumbnailsList.clientWidth : 0;
        if (main_width != currentWidth) {
            // Prevent infinite recursion - limit to 3 iterations max
            if (GDThumb._processDepth < 3) {
                GDThumb._processDepth++;
                GDThumb.process();
                GDThumb._processDepth--;
            }
        }
    },

    /**
     * Apply calculated dimensions to a thumbnail DOM element
     * Handles aspect ratio cropping, scaling, and CSS application
     * @param {jQuery} thumb - jQuery element of the thumbnail image
     * @param {number} width - Original image width
     * @param {number} height - Original image height
     * @param {number} new_width - Calculated display width
     * @param {number} new_height - Calculated display height
     * @param {boolean} is_big - Whether this is a big/representative thumbnail
     * @returns {void}
     */
    resize: function (thumb, width, height, new_width, new_height, is_big) {
        // Handle both jQuery and DOM elements
        var thumbElement = thumb.jquery ? thumb[0] : thumb;

        use_crop = true;
        if (GDThumb.method == "slide") {
            use_crop = false;
            thumbElement.style.height = "";
            thumbElement.style.width = "";
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
            thumbElement.style.height = real_height + "px";
            thumbElement.style.width = real_width + "px";
        } else if (!is_big && GDThumb.method == "square") {
            thumbElement.style.height = "";
            thumbElement.style.width = "";
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
            thumbElement.style.height = real_height + "px";
            thumbElement.style.width = real_width + "px";
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
            thumbElement.style.height = real_height + "px";
            thumbElement.style.width = real_width + "px";
        } else {
            thumbElement.style.height = "";
            thumbElement.style.width = "";
            height_crop = Math.round((height - new_height) / 2);
            width_crop = Math.round((width - new_width) / 2);
        }

        var liElement = thumbElement.closest("li");
        if (liElement) {
            liElement.style.height = new_height + "px";
            liElement.style.width = new_width + "px";
        }

        if (use_crop) {
            var aElement = thumbElement.closest("a");
            if (aElement) {
                aElement.style.clip =
                    "rect(" +
                    height_crop +
                    "px, " +
                    (new_width + width_crop) +
                    "px, " +
                    (new_height + height_crop) +
                    "px, " +
                    width_crop +
                    "px)";
                aElement.style.top = -height_crop + "px";
                aElement.style.left = -width_crop + "px";
            }
        } else {
            var aElement = thumbElement.closest("a");
            if (aElement) {
                aElement.style.top = -height_crop + "px";
                aElement.style.left = -width_crop + "px";
            }
        }
    },
};
