/*
Don't use directly. Compile on http://closure-compiler.appspot.com/home
*/
if (window.RVTS) {
    // Bidirectional infinite scroll enabled - no manual load button needed
    // User can scroll to top/bottom and content auto-loads

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

        // Vanilla JS: Extend RVTS object with methods
        RVTS = Object.assign(RVTS, {
            loading: 0,
            loadingUp: 0,
            adjust: 0,
            requestCounter: 0,
            lastProcessedRequest: 0,
            checkAutoScrollScheduled: false,

            /**
             * Remove N items from the start of thumbnails (when unloading from top)
             * @param {number} count - Number of items to remove
             * @returns {void}
             * @private
             */
            _removeFromTop: function (count) {
                var items = RVTS.$thumbs.querySelectorAll("li");
                var toRemove = Math.min(count, items.length);

                // Save current scroll position to prevent screen from moving
                var savedScrollY = window.scrollY;

                // Remove the items
                for (var i = 0; i < toRemove; i++) {
                    items[i].remove();
                }

                // Restore scroll position so screen doesn't move
                window.scrollTo(0, savedScrollY);
            },

            /**
             * Remove N items from the end of thumbnails (when unloading from bottom)
             * @param {number} count - Number of items to remove
             * @returns {void}
             * @private
             */
            _removeFromBottom: function (count) {
                var items = RVTS.$thumbs.querySelectorAll("li");
                var toRemove = Math.min(count, items.length);

                // Save current scroll position to prevent screen from moving
                var savedScrollY = window.scrollY;

                // Remove the items
                for (var i = items.length - 1; i >= Math.max(0, items.length - toRemove); i--) {
                    items[i].remove();
                }

                // Restore scroll position so screen doesn't move
                window.scrollTo(0, savedScrollY);
            },

            /**
             * Load thumbnails from earlier in the gallery (load up when scrolled to top)
             * @returns {Promise<void>}
             */
            loadUp: async function () {
                // Disabled for now
                return;

                // eslint-disable-next-line unreachable
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
                    // Vanilla JS: Show ajax loader
                    var ajaxLoader = document.getElementById("ajaxLoader");
                    if (ajaxLoader) ajaxLoader.style.display = "";

                    RVTS.loadingUp = 1;

                    var htm = await _fetchHtml(url);

                    var event = new CustomEvent("RVTS_add", {
                        detail: { html: htm, isDown: false },
                        bubbles: true,
                        cancelable: true
                    });
                    window.dispatchEvent(event);

                    if (!event.defaultPrevented)
                        RVTS.$thumbs.insertAdjacentHTML("afterbegin", htm);

                    // Update tracking after successful load
                    RVTS.start = newStart;
                    RVTS.next -= reqCount;

                    // Unload from bottom when loading from top
                    RVTS._removeFromBottom(reqCount);
                } catch (error) {
                    console.error("RVTS: Failed to load previous page:", error);
                } finally {
                    RVTS.loadingUp = 0;
                    // Vanilla JS: Hide ajax loader
                    if (!RVTS.loading) {
                        var ajaxLoader = document.getElementById("ajaxLoader");
                        if (ajaxLoader) ajaxLoader.style.display = "none";
                    }
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
                    // Vanilla JS: Show ajax loader
                    var ajaxLoader = document.getElementById("ajaxLoader");
                    if (ajaxLoader) ajaxLoader.style.display = "";

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
                            RVTS.$thumbs.insertAdjacentHTML("beforeend", htm);

                        // Unload from top when loading from bottom (maintain sliding window)
                        var items = RVTS.$thumbs.querySelectorAll("li");

                        // Only unload if we have more than 2 pages worth of items (perPage * 2)
                        if (items.length > RVTS.perPage * 2) {
                            RVTS._removeFromTop(RVTS.perPage);
                            RVTS.start += RVTS.perPage;
                        }
                    } else if (currentRequest > RVTS.lastProcessedRequest) {
                        // Out of order - ignore this response to prevent duplicates
                        console.warn("RVTS: Out of order response #" + currentRequest + ", expected #" + (RVTS.lastProcessedRequest + 1));
                    }
                } catch (error) {
                    console.error("RVTS: Failed to load next page:", error);
                } finally {
                    RVTS.loading = 0;
                    // Vanilla JS: Hide ajax loader
                    if (!RVTS.loadingUp) {
                        var ajaxLoader = document.getElementById("ajaxLoader");
                        if (ajaxLoader) ajaxLoader.style.display = "none";
                    }
                    window.dispatchEvent(new Event("RVTS_loaded"));
                }
            },

            /**
             * Check if user has scrolled near the bottom of thumbnails
             * @param {Event} evt - Optional scroll/resize event
             * @returns {number} 1 if near bottom and load triggered, 0 otherwise
             */
            checkAutoScroll: function (evt) {
                // Vanilla JS: Calculate thumbnail bottom position
                var tBot = RVTS.$thumbs.offsetTop + RVTS.$thumbs.offsetHeight;
                // Vanilla JS: Calculate window bottom position
                var wBot = window.scrollY + window.innerHeight;
                // Proactively load before user reaches bottom (800px buffer for seamless loading)
                tBot -= !evt ? 0 : 800;
                return tBot <= wBot ? (RVTS.doAutoScroll(), 1) : 0;
            },

            /**
             * Check if user has scrolled near the top of thumbnails
             * @param {Event} evt - Optional scroll/resize event
             * @returns {number} 1 if near top and load triggered, 0 otherwise
             */
            checkAutoScrollUp: function (evt) {
                // Vanilla JS: Calculate thumbnail top position
                var tTop = RVTS.$thumbs.offsetTop;
                // Vanilla JS: Calculate window top position
                var wTop = window.scrollY;
                // Proactively load before user reaches top (800px buffer for seamless loading)
                tTop += !evt ? 0 : 800;
                return wTop < tTop ? (RVTS.loadUp(), 1) : 0;
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
                    RVTS.checkAutoScrollUp(evt);
                    RVTS.checkAutoScrollScheduled = false;
                });
            },

            /**
             * Initialize the infinite scroll plugin
             * @returns {void}
             */
            engage: function () {
                // Vanilla JS: Get thumbnails container
                RVTS.$thumbs = document.getElementById("thumbnails");

                // Vanilla JS: Insert ajax loader after thumbnails
                if (RVTS.$thumbs) {
                    var loaderHtml = '<div id="ajaxLoader" style="display:none;position:fixed;bottom:32px;right:1%;z-index:999"><img src="' +
                        RVTS.ajaxLoaderImage +
                        '" width="128" height="15" alt="~"></div>';
                    RVTS.$thumbs.insertAdjacentHTML("afterend", loaderHtml);
                }

                if ("#top" == window.location.hash) window.scrollTo(0, 0);

                // Adjust pagination size based on initial viewport fill
                if (RVTS.$thumbs) {
                    var thumbsHeight = RVTS.$thumbs.offsetHeight + (RVTS.$thumbs.offsetWidth - RVTS.$thumbs.clientWidth);
                    var windowHeight = window.innerHeight;
                    if (thumbsHeight < windowHeight) RVTS.adjust = 1;
                    else if (RVTS.$thumbs.offsetHeight > 2 * windowHeight)
                        RVTS.adjust = -1;
                }

                // Vanilla JS: Add scroll and resize event listeners
                window.addEventListener("scroll", RVTS.throttleCheckAutoScroll);
                window.addEventListener("resize", RVTS.throttleCheckAutoScroll);
                if (RVTS.checkAutoScroll())
                    window.setTimeout(RVTS.checkAutoScroll, 1500);
            },
        }); //end extend

        // Vanilla JS: Document ready - engage when DOM is ready
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", function () {
                if ("#top" == window.location.hash) window.scrollTo(0, 0);
                window.setTimeout(RVTS.engage, 150);
            });
        } else {
            // Already loaded
            if ("#top" == window.location.hash) window.scrollTo(0, 0);
            window.setTimeout(RVTS.engage, 150);
        }

        if (window.history.replaceState) {
            var iniStart = RVTS.start;
            // Vanilla JS: Listen for RVTS_loaded event once
            window.addEventListener("RVTS_loaded", function onRVTSLoaded() {
                window.removeEventListener("RVTS_loaded", onRVTSLoaded);

                // Vanilla JS: Listen for unload to save scroll position
                window.addEventListener("unload", function () {
                    var threshold = Math.max(0, window.scrollY - 60);
                    var elts = RVTS.$thumbs.querySelectorAll("li");
                    for (var i = 0; i < elts.length; i++) {
                        var rect = elts[i].getBoundingClientRect();
                        var offsetTop = rect.top + window.scrollY;
                        if (offsetTop >= threshold) {
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
}
