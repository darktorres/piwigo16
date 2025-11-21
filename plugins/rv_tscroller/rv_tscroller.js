/*
Don't use directly. Compile on http://closure-compiler.appspot.com/home
*/
if (window.jQuery && window.RVTS)
    (function ($) {
        if (RVTS.start > 0) {
            var $f = $(".navigationBar A[rel=first]");
            $("#thumbnails").before(
                '<div id=rvtsUp style="text-align:center;font-size:120%;margin:10px"><a href="' +
                    $f.attr("href") +
                    '">' +
                    $f.html() +
                    '</a> | <a href="javascript:RVTS.loadUp()">' +
                    RVTS.prevMsg +
                    "</a></div>",
            );
        }

        /**
         * Fetch HTML content from URL
         * @param {string} url - URL to fetch from
         * @returns {Promise<string>} HTML content
         * @private
         */
        var _fetchHtml = async function(url) {
            var response = await fetch(url);
            if (!response.ok) {
                throw new Error("HTTP " + response.status + ": " + response.statusText);
            }
            return await response.text();
        };

        RVTS = $.fn.extend(RVTS, {
            loading: 0,
            loadingUp: 0,
            adjust: 0,
            requestCounter: 0,
            lastProcessedRequest: 0,
            checkAutoScrollScheduled: false,

            /**
             * Load thumbnails from earlier in the gallery (load up when scrolled to top)
             * @returns {Promise<void>}
             */
            loadUp: async function () {
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

                try {
                    $("#ajaxLoader").show();
                    RVTS.loadingUp = 1;

                    var htm = await _fetchHtml(url);
                    RVTS.start = newStart;

                    var event = new CustomEvent("RVTS_add", {
                        detail: { html: htm, isDown: false },
                        bubbles: true,
                        cancelable: true
                    });
                    window.dispatchEvent(event);

                    if (!event.defaultPrevented)
                        RVTS.$thumbs.prepend(htm);

                    if (RVTS.start <= 0) $("#rvtsUp").remove();
                } catch (error) {
                    console.error("RVTS: Failed to load previous page:", error);
                } finally {
                    RVTS.loadingUp = 0;
                    RVTS.loading || $("#ajaxLoader").hide();
                    window.dispatchEvent(new Event("RVTS_loaded"));
                }
            },

            /**
             * Load next page of thumbnails automatically when user scrolls near bottom
             * @returns {Promise<void>}
             */
            doAutoScroll: async function () {
                if (RVTS.loading || RVTS.next >= RVTS.total) return;

                var url = RVTS.ajaxUrlModel
                    .replace("%start%", RVTS.next)
                    .replace("%per%", RVTS.perPage);

                if (RVTS.adjust) {
                    url += "&adj=" + RVTS.adjust;
                    RVTS.adjust = 0;
                }

                // Track request order to handle out-of-order responses from network delays
                var currentRequest = ++RVTS.requestCounter;

                try {
                    $("#ajaxLoader").show();
                    RVTS.loading = 1;

                    var htm = await _fetchHtml(url);

                    // Only process if this is the next expected response (handles race conditions)
                    if (currentRequest === RVTS.lastProcessedRequest + 1) {
                        RVTS.next += RVTS.perPage;
                        RVTS.lastProcessedRequest = currentRequest;

                        var event = new CustomEvent("RVTS_add", {
                            detail: { html: htm, isDown: true },
                            bubbles: true,
                            cancelable: true
                        });
                        window.dispatchEvent(event);

                        if (!event.defaultPrevented)
                            RVTS.$thumbs.append(htm);
                    } else if (currentRequest > RVTS.lastProcessedRequest) {
                        // Out of order - ignore this response to prevent duplicates
                        console.warn("RVTS: Out of order response #" + currentRequest + ", expected #" + (RVTS.lastProcessedRequest + 1));
                    }
                } catch (error) {
                    console.error("RVTS: Failed to load next page:", error);
                } finally {
                    RVTS.loading = 0;
                    RVTS.loadingUp || $("#ajaxLoader").hide();
                    window.dispatchEvent(new Event("RVTS_loaded"));
                }
            },

            /**
             * Check if user has scrolled near the bottom of thumbnails
             * @param {Event} evt - Optional scroll/resize event
             * @returns {number} 1 if near bottom and load triggered, 0 otherwise
             */
            checkAutoScroll: function (evt) {
                var tBot =
                    RVTS.$thumbs.position().top + RVTS.$thumbs.outerHeight();
                var wBot = $(window).scrollTop() + $(window).height();
                tBot -= !evt ? 0 : 100; // Begin loading 100 pixels before end
                return tBot <= wBot ? (RVTS.doAutoScroll(), 1) : 0;
            },

            /**
             * Throttle scroll/resize events using requestAnimationFrame
             * @param {Event} evt - Scroll or resize event
             * @returns {void}
             */
            throttleCheckAutoScroll: function (evt) {
                if (RVTS.checkAutoScrollScheduled) return;
                RVTS.checkAutoScrollScheduled = true;
                var raf = window.requestAnimationFrame || function(cb) { return window.setTimeout(cb, 16); };
                raf(function() {
                    RVTS.checkAutoScroll(evt);
                    RVTS.checkAutoScrollScheduled = false;
                });
            },

            /**
             * Initialize the infinite scroll plugin
             * @returns {void}
             */
            engage: function () {
                var $w = $(window);
                RVTS.$thumbs = $("#thumbnails");
                RVTS.$thumbs.after(
                    '<div id="ajaxLoader" style="display:none;position:fixed;bottom:32px;right:1%;z-index:999"><img src="' +
                        RVTS.ajaxLoaderImage +
                        '" width="128" height="15" alt="~"></div>',
                );

                if ("#top" == window.location.hash) window.scrollTo(0, 0);

                // Adjust pagination size based on initial viewport fill
                if (RVTS.$thumbs.outerHeight() < $w.height()) RVTS.adjust = 1;
                else if (RVTS.$thumbs.height() > 2 * $w.height())
                    RVTS.adjust = -1;

                $w.on("scroll resize", RVTS.throttleCheckAutoScroll);
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
                    var elts = RVTS.$thumbs.children("li");
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
                                window.history.replaceState(
                                    null,
                                    "",
                                    url + "#top",
                                );
                            }
                            break;
                        }
                    }
                });
            });
        }
    })(jQuery);
