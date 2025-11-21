// Initialize admin page
(function () {
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
    var pending_next_page = null;
    var last_image_show_time = 0;
    var urlProcessingDone = false;

    // Vanilla JS: Replace jQuery.Deferred with simple callbacks
    window.gdThumb_start = function () {
        urlProcessingDone = false;

        // Vanilla JS: Show/hide controls
        setTimeout(function () {
            var generateCache = document.getElementById("generate_cache");
            if (generateCache) generateCache.style.display = "";

            var startLink = document.getElementById("startLink");
            if (startLink) {
                startLink.disabled = true;
                startLink.style.opacity = "0.5";
            }

            var pauseStopLinks = document.querySelectorAll("#pauseLink,#stopLink");
            pauseStopLinks.forEach(function (link) {
                link.disabled = false;
                link.style.opacity = "1";
            });
        }, 0);

        loader.pause(false);
        updateStats();
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
        var data = { prev_page: page_token, max_urls: 500, types: [] };
        // Vanilla JS: Fetch instead of jQuery.post
        var params = new URLSearchParams();
        params.append("prev_page", page_token);
        params.append("max_urls", 500);

        fetch("admin.php?page=plugin-GDThumb&getMissingDerivative=", {
            method: "POST",
            body: params,
        })
            .then(function (response) {
                return response.json();
            })
            .then(wsData)
            .catch(wsError);
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
        // Error handling - marking URL processing as done
        urlProcessingDone = true;
    }

    function updateStats() {
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
        updateStats();
        if (img) {
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
                            feedbackWrap.style.display = "";
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
            getUrls(pending_next_page);
            pending_next_page = null;
        } else if (loader.remaining() == 0 && urlProcessingDone) {
            // All loading and processing complete
        }
    }
})();
