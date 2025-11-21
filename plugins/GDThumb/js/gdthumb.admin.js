// Initialize admin page
(function () {
    console.log("[GDThumb] Admin script initializing...");

    // Note: tipTip is a jQuery UI plugin - would need separate tooltip library
    // For now, skipping tooltip implementation
    // TODO: Replace with vanilla JS tooltip library if needed

    // Vanilla JS: Hide infos div after 4 seconds
    var infosDiv = document.querySelector("div.infos");
    if (infosDiv) {
        setTimeout(function () {
            infosDiv.style.display = "none";
        }, 4000);
    }

    var loader = new ImageLoader({ onChanged: loaderChanged });
    console.log("[GDThumb] ImageLoader created");
    var pending_next_page = null;
    var last_image_show_time = 0;
    var urlProcessingDone = false;

    // Vanilla JS: Replace jQuery.Deferred with simple callbacks
    window.gdThumb_start = function () {
        console.log("[GDThumb] gdThumb_start() called");
        urlProcessingDone = false;
        console.log("[GDThumb] urlProcessingDone = false");

        // Vanilla JS: Show/hide controls
        setTimeout(function () {
            console.log("[GDThumb] Updating UI controls");
            var generateCache = document.getElementById("generate_cache");
            if (generateCache) {
                generateCache.style.display = "block";
                console.log("[GDThumb] Showed generate_cache fieldset");
            } else {
                console.warn("[GDThumb] generate_cache element not found!");
            }

            var startLink = document.getElementById("startLink");
            if (startLink) {
                startLink.disabled = true;
                startLink.style.opacity = "0.5";
                console.log("[GDThumb] Disabled start button");
            }

            var pauseStopLinks = document.querySelectorAll("#pauseLink,#stopLink");
            console.log("[GDThumb] Found " + pauseStopLinks.length + " pause/stop buttons");
            pauseStopLinks.forEach(function (link) {
                link.disabled = false;
                link.style.opacity = "1";
            });
        }, 0);

        console.log("[GDThumb] Unpausing loader");
        loader.pause(false);
        console.log("[GDThumb] Calling updateStats()");
        updateStats();
        console.log("[GDThumb] Calling getUrls(0)");
        getUrls(0);
    };

    window.gdThumb_pause = function () {
        loader.pause(!loader.pause());
    };

    window.gdThumb_stop = function () {
        urlProcessingDone = true;
        loader.clear();
    };

    function getUrls(page_token) {
        console.log("[GDThumb] getUrls(" + page_token + ") called");
        var data = { prev_page: page_token, max_urls: 500, types: [] };
        // Vanilla JS: Fetch instead of jQuery.post
        var params = new URLSearchParams();
        params.append("prev_page", page_token);
        params.append("max_urls", 500);

        console.log("[GDThumb] Fetching from admin.php?page=plugin-GDThumb&getMissingDerivative=");
        fetch("admin.php?page=plugin-GDThumb&getMissingDerivative=", {
            method: "POST",
            body: params,
        })
            .then(function (response) {
                console.log("[GDThumb] Fetch response received, status: " + response.status);
                if (!response.ok) {
                    throw new Error("HTTP " + response.status + ": " + response.statusText);
                }
                console.log("[GDThumb] Response OK, parsing JSON...");
                return response.json();
            })
            .then(function (data) {
                console.log("[GDThumb] JSON parsed, received data:", data);
                wsData(data);
            })
            .catch(wsError);
    }

    function wsData(data) {
        console.log("[GDThumb] wsData() called with " + (data.urls ? data.urls.length : 0) + " URLs");
        console.log("[GDThumb] data.next_page = " + data.next_page);

        if (!data.urls) {
            console.error("[GDThumb] ERROR: data.urls is missing!");
            return;
        }

        loader.add(data.urls);
        console.log("[GDThumb] Added URLs to loader, remaining: " + loader.remaining());

        if (data.next_page) {
            console.log("[GDThumb] Has next_page: " + data.next_page);
            console.log("[GDThumb] loader.pause() = " + loader.pause() + ", loader.remaining() = " + loader.remaining());
            if (loader.pause() || loader.remaining() > 100) {
                pending_next_page = data.next_page;
                console.log("[GDThumb] Storing pending_next_page, will fetch later");
            } else {
                console.log("[GDThumb] Fetching next page immediately");
                getUrls(data.next_page);
            }
        } else {
            // No more pages - mark URL fetching as complete
            urlProcessingDone = true;
            console.log("[GDThumb] No next_page, setting urlProcessingDone = true");
        }
    }

    function wsError(error) {
        // Error handling - marking URL processing as done
        urlProcessingDone = true;
        console.error("Failed to fetch URLs:", error);

        // Show error message to user
        var errorList = document.getElementById("errorList");
        if (errorList) {
            var errorMsg = document.createElement("div");
            errorMsg.style.color = "red";
            errorMsg.style.fontWeight = "bold";
            errorMsg.style.padding = "10px";
            errorMsg.textContent = "Error: Failed to fetch image list from server. " + error.message;
            errorList.insertBefore(errorMsg, errorList.firstChild);
        }
    }

    function updateStats() {
        console.log("[GDThumb] updateStats(): loaded=" + loader.loaded + ", errors=" + loader.errors + ", remaining=" + loader.remaining());

        // Vanilla JS: Update text content
        var loadedEl = document.getElementById("loaded");
        if (loadedEl) loadedEl.textContent = loader.loaded;

        var errorsEl = document.getElementById("errors");
        if (errorsEl) errorsEl.textContent = loader.errors;

        var remainingEl = document.getElementById("remaining");
        if (remainingEl) remainingEl.textContent = loader.remaining();

        if (loader.remaining() == 0) {
            var startLink = document.getElementById("startLink");
            if (startLink) {
                startLink.disabled = false;
                startLink.style.opacity = "1";
            }

            var pauseStopLinks = document.querySelectorAll("#pauseLink,#stopLink");
            pauseStopLinks.forEach(function (link) {
                link.disabled = true;
                link.style.opacity = "0.5";
            });
        }
    }

    function loaderChanged(type, img) {
        console.log("[GDThumb] loaderChanged(" + type + ", " + (img ? "img" : "null") + ")");
        updateStats();
        if (img) {
            console.log("[GDThumb] Image event: " + type + " - " + img.src);
            if (type === "load") {
                // Vanilla JS: Replace jQuery.now() with Date.now()
                var now = Date.now();
                if (now - last_image_show_time > 3000) {
                    last_image_show_time = now;
                    var h = img.height;
                    var url = img.src;

                    // Vanilla JS: Hide feedback, then show with new image
                    var feedbackWrap = document.getElementById("feedbackWrap");
                    var feedbackImg = document.getElementById("feedbackImg");

                    if (feedbackWrap && feedbackImg) {
                        feedbackWrap.style.display = "none";

                        // Update image after short delay
                        setTimeout(function () {
                            last_image_show_time = Date.now();
                            if (h > 300)
                                feedbackImg.height = 300;
                            else
                                feedbackImg.removeAttribute("height");
                            feedbackImg.src = url;
                            feedbackWrap.style.display = "block";
                        }, 300);
                    }
                }
            } else {
                // Vanilla JS: Add error link
                var errorList = document.getElementById("errorList");
                if (errorList) {
                    var link = document.createElement("div");
                    link.innerHTML =
                        '<a href="' + img.src + '">' + img.src + "</a>" + "<br>";
                    errorList.insertBefore(link.firstChild, errorList.firstChild);
                }
            }
        }

        if (pending_next_page && 100 > loader.remaining()) {
            console.log("[GDThumb] Queue below 100, fetching pending_next_page: " + pending_next_page);
            getUrls(pending_next_page);
            pending_next_page = null;
        } else if (loader.remaining() == 0 && urlProcessingDone) {
            // All loading and processing complete
            console.log("[GDThumb] ✓ ALL COMPLETE! remaining=0 and urlProcessingDone=true");
        } else {
            console.log("[GDThumb] Waiting... remaining=" + loader.remaining() + ", urlProcessingDone=" + urlProcessingDone);
        }
    }
})();
