/*
Don't use directly. Compile on http://closure-compiler.appspot.com/home
*/
if (window.jQuery && window.RVTS)
    (function ($) {
        if (RVTS.start > 0) {
            var $f = $(".navigationBar A[rel=first]");
            var $upDiv = $('<div id="rvtsUp" style="text-align:center;font-size:120%;margin:10px"></div>');
            $upDiv.append($('<a></a>').attr("href", $f.attr("href")).text($f.text()));
            $upDiv.append(" | ");
            $upDiv.append($('<a href="#"></a>').text(RVTS.prevMsg).on("click", function(e) { e.preventDefault(); RVTS.loadUp(); }));
            $("#thumbnails").before($upDiv);
        }

        RVTS = $.fn.extend(RVTS, {
            loading: 0,
            loadingUp: 0,
            adjust: 0,

            loadUp: function () {
                if (RVTS.loadingUp || RVTS.start <= 0) return;
                var newStart = RVTS.start - RVTS.perPage;
                var reqCount = RVTS.perPage;
                if (newStart < 0) {
                    reqCount += newStart;
                    newStart = 0;
                }
                var url = RVTS.ajaxUrlModel
                    .replace("%start%", newStart)
                    .replace("%per%", reqCount);
                $("#ajaxLoader").show();
                RVTS.loadingUp = 1;
                $.ajax({
                    type: "GET",
                    dataType: "html",
                    url: url,
                    success: function (htm) {
                        RVTS.start = newStart;

                        var event = jQuery.Event("RVTS_add");
                        $(window).trigger(event, [htm, false]);

                        if (!event.isDefaultPrevented())
                            RVTS.$thumbs.prepend(htm);

                        if (RVTS.start <= 0) $("#rvtsUp").remove();
                    },
                    complete: function () {
                        RVTS.loadingUp = 0;
                        RVTS.loading || $("#ajaxLoader").hide();
                        $(window).trigger("RVTS_loaded", 0);
                    },
                });
            },

            doAutoScroll: function () {
                if (RVTS.loading || RVTS.next >= RVTS.total) return;
                var url = RVTS.ajaxUrlModel
                    .replace("%start%", RVTS.next)
                    .replace("%per%", RVTS.perPage);
                if (RVTS.adjust) {
                    url += "&adj=" + RVTS.adjust;
                    RVTS.adjust = 0;
                }
                $("#ajaxLoader").show();
                RVTS.loading = 1;
                $.ajax({
                    type: "GET",
                    dataType: "html",
                    url: url,
                    success: function (htm) {
                        RVTS.next += RVTS.perPage;
                        var event = jQuery.Event("RVTS_add");
                        $(window).trigger(event, [htm, true]);

                        if (!event.isDefaultPrevented())
                            RVTS.$thumbs.append(htm);
                    },
                    complete: function () {
                        RVTS.loading = 0;
                        RVTS.loadingUp || $("#ajaxLoader").hide();
                        $(window).trigger("RVTS_loaded", 1);
                    },
                });
            },

            checkAutoScroll: function (evt) {
                var tBot =
                    RVTS.$thumbs.position().top + RVTS.$thumbs.outerHeight();
                var wBot = $(window).scrollTop() + $(window).height();
                tBot -= !evt ? 0 : 100; //begin 100 pixels before end
                return tBot <= wBot ? (RVTS.doAutoScroll(), 1) : 0;
            },

            engage: function () {
                var $w = $(window);
                RVTS.$thumbs = $("#thumbnails");
                RVTS.$thumbs.after(
                    '<div id="ajaxLoader" style="display:none;position:fixed;bottom:32px;right:1%;z-index:999"><img src="' +
                        RVTS.ajaxLoaderImage +
                        '" width="128" height="15" alt="~"></div>',
                );

                if ("#top" == window.location.hash) window.scrollTo(0, 0);

                if (RVTS.$thumbs.outerHeight() < $w.height()) RVTS.adjust = 1;
                else if (RVTS.$thumbs.height() > 2 * $w.height())
                    RVTS.adjust = -1;
                $w.on("scroll resize", RVTS.checkAutoScroll);
                if (RVTS.checkAutoScroll())
                    window.setTimeout(RVTS.checkAutoScroll, 1500);
            },
        }); //end extend

        $(document).ready(function () {
            if ("#top" == window.location.hash) window.scrollTo(0, 0);
            window.setTimeout(RVTS.engage, 150);
        });

        if (window.history.replaceState) {
            var iniStart = RVTS.start;
            $(window).one("RVTS_loaded", function () {
                $(window).on("unload", function () {
                    var threshold = Math.max(0, $(window).scrollTop() - 60);
                    var elts = RVTS.$thumbs.children();
                    for (var i = 0; i < elts.length; i++) {
                        var offset = $(elts[i]).offset();
                        if (offset.top >= threshold) {
                            var start = RVTS.start + i;
                            var delta = start - iniStart;
                            if (delta < 0 || delta >= RVTS.perPage) {
                                var url = start
                                    ? RVTS.urlModel.replace("%start%", start)
                                    : RVTS.urlModel.replace(
                                          "/start-%start%",
                                          "",
                                      );
                                try {
                                    window.history.replaceState(
                                        null,
                                        "",
                                        url + "#top",
                                    );
                                } catch (e) {}
                            }
                            break;
                        }
                    }
                });
            });
        }
    })(jQuery);

// Album / folder infinite scroll
if (window.jQuery && window.RVTS_CATS)
    (function ($) {
        RVTS_CATS = $.fn.extend(RVTS_CATS, {
            loading: 0,

            doAutoScroll: function () {
                if (RVTS_CATS.loading || RVTS_CATS.next >= RVTS_CATS.total) return;
                var url = RVTS_CATS.ajaxUrlModel
                    .replace('%startcat%', RVTS_CATS.next);
                $("#ajaxLoader").show();
                RVTS_CATS.loading = 1;
                $.ajax({
                    type: "GET",
                    dataType: "html",
                    url: url,
                    success: function (htm) {
                        RVTS_CATS.next += RVTS_CATS.perPage;
                        var $src = $(htm);
                        var $wrap = $src.filter("[data-album-grid]");
                        if (!$wrap.length) $wrap = $src.find("[data-album-grid]");
                        var $items = $wrap.length ? $wrap.children() : $src;
                        RVTS_CATS.$thumbs.append($items);
                        if (typeof GDThumb !== 'undefined' && typeof GDThumb.build === 'function') {
                            GDThumb.build();
                        }
                    },
                    complete: function () {
                        RVTS_CATS.loading = 0;
                        $("#ajaxLoader").hide();
                    },
                });
            },

            checkAutoScroll: function () {
                var tBot =
                    RVTS_CATS.$thumbs.position().top + RVTS_CATS.$thumbs.outerHeight();
                var wBot = $(window).scrollTop() + $(window).height();
                tBot -= 100;
                return tBot <= wBot ? (RVTS_CATS.doAutoScroll(), 1) : 0;
            },

            engage: function () {
                RVTS_CATS.$thumbs = $("[data-album-grid]").first();
                if (!RVTS_CATS.$thumbs.length) return;
                RVTS_CATS.$thumbs.after(
                    '<div id="ajaxLoader" style="display:none;position:fixed;bottom:32px;right:1%;z-index:999"><img src="' +
                        RVTS_CATS.ajaxLoaderImage +
                        '" width="128" height="15" alt="~"></div>',
                );
                $(window).on("scroll resize", RVTS_CATS.checkAutoScroll);
                if (RVTS_CATS.checkAutoScroll())
                    window.setTimeout(RVTS_CATS.checkAutoScroll, 1500);
            },
        });

        $(document).ready(function () {
            window.setTimeout(RVTS_CATS.engage, 150);
        });
    })(jQuery);
