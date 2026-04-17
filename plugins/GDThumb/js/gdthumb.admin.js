(function () {
    // Fade out div.infos after 4 s
    document.querySelectorAll("div.infos").forEach(function(el) {
        setTimeout(function() {
            el.animate([{opacity: 1}, {opacity: 0}], {duration: 600, fill: 'forwards'}).onfinish = function() {
                el.style.display = 'none';
            };
        }, 4000);
    });

    var loader = new ImageLoader({ onChanged: loaderChanged });
    var pending_next_page = null;
    var last_image_show_time = 0;
    var allDoneDfd;
    var urlDfd;

    // Minimal deferred factory (resolve/reject/always/state)
    function _makeDeferred() {
        var _res, _rej, _state = 'pending';
        var p = new Promise(function(res, rej) { _res = res; _rej = rej; });
        return {
            resolve: function() { if (_state === 'pending') { _state = 'resolved'; _res(); } },
            reject:  function() { if (_state === 'pending') { _state = 'rejected'; _rej(); } },
            always:  function(fn) { p.then(fn, fn); },
            state:   function() { return _state; }
        };
    }

    function _setBtn(id, disabled, opacity) {
        var el = document.getElementById(id);
        if (!el) return;
        el.disabled = disabled;
        el.style.opacity = opacity;
    }

    function _setGroup(sel, disabled, opacity) {
        document.querySelectorAll(sel).forEach(function(el) {
            el.disabled = disabled;
            el.style.opacity = opacity;
        });
    }

    window.gdThumb_start = function () {
        allDoneDfd = _makeDeferred();
        urlDfd     = _makeDeferred();

        allDoneDfd.always(function () {
            _setBtn('startLink', false, 1);
            _setGroup('#pauseLink,#stopLink', true, 0.5);
        });

        urlDfd.always(function () {
            if (loader.remaining() == 0) allDoneDfd.resolve();
        });

        setTimeout(function () {
            var gc = document.getElementById('generate_cache');
            if (gc) gc.style.display = '';
            _setBtn('startLink', true, 0.5);
            _setGroup('#pauseLink,#stopLink', false, 1);
        }, 0);

        loader.pause(false);
        updateStats();
        getUrls(0);
    };

    window.gdThumb_pause = function () {
        loader.pause(!loader.pause());
    };

    window.gdThumb_stop = function () {
        loader.clear();
        urlDfd.resolve();
    };

    function getUrls(page_token) {
        var data = new URLSearchParams({ prev_page: page_token, max_urls: 500 });
        // types[] is empty so omit it
        fetch('admin.php?page=plugin-GDThumb&getMissingDerivative=', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: data
        })
        .then(function(r) { return r.json(); })
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
        } else {
            urlDfd.resolve();
        }
    }

    function wsError() {
        urlDfd.reject();
    }

    function updateStats() {
        var loadedEl    = document.getElementById('loaded');
        var errorsEl    = document.getElementById('errors');
        var remainingEl = document.getElementById('remaining');
        if (loadedEl)    loadedEl.textContent    = loader.loaded;
        if (errorsEl)    errorsEl.textContent     = loader.errors;
        if (remainingEl) remainingEl.textContent  = loader.remaining();

        if (loader.remaining() == 0) {
            _setBtn('startLink', false, 1);
            _setGroup('#pauseLink,#stopLink', true, 0.5);
        }
    }

    function loaderChanged(type, img) {
        updateStats();
        if (img) {
            if (type === "load") {
                var now = Date.now();
                if (now - last_image_show_time > 3000) {
                    last_image_show_time = now;
                    var h   = img.height;
                    var url = img.src;
                    var wrap = document.getElementById('feedbackWrap');
                    var fimg = document.getElementById('feedbackImg');
                    if (wrap && fimg) {
                        // Slide down (fade out), swap src, slide up (fade in)
                        wrap.style.transition = 'opacity 0.25s';
                        wrap.style.opacity = '0';
                        setTimeout(function() {
                            last_image_show_time = Date.now();
                            if (h > 300) fimg.setAttribute('height', 300);
                            else fimg.removeAttribute('height');
                            fimg.setAttribute('src', url);
                            wrap.style.opacity = '1';
                            setTimeout(function() { wrap.style.transition = ''; }, 250);
                        }, 250);
                    }
                }
            } else {
                var errList = document.getElementById('errorList');
                if (errList) {
                    errList.insertAdjacentHTML('afterbegin',
                        '<a href="' + img.src + '">' + img.src + '</a><br>');
                }
            }
        }
        if (pending_next_page && 100 > loader.remaining()) {
            getUrls(pending_next_page);
            pending_next_page = null;
        } else if (
            loader.remaining() == 0 &&
            urlDfd &&
            (urlDfd.state() === "resolved" || urlDfd.state() === "rejected")
        ) {
            allDoneDfd.resolve();
        }
    }
})();
