(function ($) {
    jQuery('input[name^="cache"]').tipTip({
        delay: 0,
        fadeIn: 200,
        fadeOut: 200,
    });
    $("div.infos")
        .delay(4000)
        .slideUp("slow", function () {
            $("div.infos").hide();
        });

    var loader = new ImageLoader({ onChanged: loaderChanged });
    var pending_next_page = null;
    var last_image_show_time = 0;
    var allDoneDfd;
    var urlDfd;

    jQuery.gdThumb_start = function () {
        allDoneDfd = jQuery.Deferred();
        urlDfd = jQuery.Deferred();

        allDoneDfd.always(function () {
            jQuery("#startLink").removeAttr("disabled").css("opacity", 1);
            jQuery("#pauseLink,#stopLink")
                .attr("disabled", true)
                .css("opacity", 0.5);
        });

        urlDfd.always(function () {
            if (loader.remaining() == 0) allDoneDfd.resolve();
        });

        setTimeout(function () {
            jQuery("#generate_cache").show();
            jQuery("#startLink").attr("disabled", true).css("opacity", 0.5);
            jQuery("#pauseLink,#stopLink")
                .removeAttr("disabled")
                .css("opacity", 1);
        }, 0);

        loader.pause(false);
        updateStats();
        getUrls(0);
    };

    jQuery.gdThumb_pause = function () {
        loader.pause(!loader.pause());
    };

    jQuery.gdThumb_stop = function () {
        loader.clear();
        urlDfd.resolve();
    };

    function getUrls(page_token) {
        data = { prev_page: page_token, max_urls: 500, types: [] };
        jQuery
            .post(
                "admin.php?page=plugin-GDThumb&getMissingDerivative=",
                data,
                wsData,
                "json",
            )
            .fail(wsError);
    }

    function wsData(data) {
        loader.add(data.urls);
        if (data.next_page) {
            if (loader.pause() || loader.remaining() > 100) {
                pending_next_page = data.next_page;
            } else {
                getUrls(data.next_page);
            }
        }
    }

    function wsError() {
        urlDfd.reject();
    }

    function updateStats() {
        jQuery("#loaded").text(loader.loaded);
        jQuery("#errors").text(loader.errors);
        jQuery("#remaining").text(loader.remaining());

        if (loader.remaining() == 0) {
            jQuery("#startLink").attr("disabled", false).css("opacity", 1);
            jQuery("#pauseLink,#stopLink")
                .attr("disabled", true)
                .css("opacity", 0.5);
        }
    }

    function loaderChanged(type, img) {
        updateStats();
        if (img) {
            if (type === "load") {
                var now = jQuery.now();
                if (now - last_image_show_time > 3000) {
                    last_image_show_time = now;
                    var h = img.height;
                    var url = img.src;
                    jQuery("#feedbackWrap").hide(
                        "slide",
                        { direction: "down" },
                        function () {
                            last_image_show_time = jQuery.now();
                            if (h > 300)
                                jQuery("#feedbackImg").attr("height", 300);
                            else jQuery("#feedbackImg").removeAttr("height");
                            jQuery("#feedbackImg").attr("src", url);
                            jQuery("#feedbackWrap").show("slide", {
                                direction: "up",
                            });
                        },
                    );
                }
            } else {
                jQuery("#errorList").prepend(
                    '<a href="' + img.src + '">' + img.src + "</a>" + "<br>",
                );
            }
        }
        if (pending_next_page && 100 > loader.remaining()) {
            getUrls(pending_next_page);
            pending_next_page = null;
        } else if (
            loader.remaining() == 0 &&
            jQuery.isFunction(urlDfd.isResolved) &&
            (urlDfd.isResolved() || urlDfd.isRejected())
        ) {
            allDoneDfd.resolve();
        }
    }
})(jQuery);
