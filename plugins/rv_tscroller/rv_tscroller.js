/*
Don't use directly. Compile on http://closure-compiler.appspot.com/home
*/
if (window.RVTS)
    (function () {
        if (RVTS.start > 0) {
            var fEl = document.querySelector(".navigationBar A[rel=first]");
            var upDiv = document.createElement('div');
            upDiv.id = 'rvtsUp';
            upDiv.style.cssText = 'text-align:center;font-size:120%;margin:10px';
            if (fEl) {
                var firstA = document.createElement('a');
                firstA.href = fEl.href;
                firstA.textContent = fEl.textContent;
                upDiv.appendChild(firstA);
                upDiv.appendChild(document.createTextNode(' | '));
            }
            var prevA = document.createElement('a');
            prevA.href = '#';
            prevA.textContent = RVTS.prevMsg;
            prevA.addEventListener('click', function(e) { e.preventDefault(); RVTS.loadUp(); });
            upDiv.appendChild(prevA);
            var thumbsContEl = document.getElementById('thumbnails');
            if (thumbsContEl) thumbsContEl.insertAdjacentElement('beforebegin', upDiv);
        }

        Object.assign(RVTS, {
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
                var loaderEl = document.getElementById('ajaxLoader');
                if (loaderEl) loaderEl.style.display = '';
                RVTS.loadingUp = 1;
                fetch(url)
                    .then(function(r) { return r.text(); })
                    .then(function(htm) {
                        RVTS.start = newStart;
                        var evt = new CustomEvent('RVTS_add', {cancelable: true, detail: {htm: htm, addToEnd: false}});
                        window.dispatchEvent(evt);
                        if (!evt.defaultPrevented)
                            RVTS.$thumbs.insertAdjacentHTML('afterbegin', htm);
                        if (RVTS.start <= 0) {
                            var rvtsUpEl = document.getElementById('rvtsUp');
                            if (rvtsUpEl) rvtsUpEl.remove();
                        }
                    })
                    .catch(function() {})
                    .finally(function() {
                        RVTS.loadingUp = 0;
                        if (!RVTS.loading && loaderEl) loaderEl.style.display = 'none';
                        window.dispatchEvent(new CustomEvent('RVTS_loaded', {detail: 0}));
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
                var loaderEl = document.getElementById('ajaxLoader');
                if (loaderEl) loaderEl.style.display = '';
                RVTS.loading = 1;
                fetch(url)
                    .then(function(r) { return r.text(); })
                    .then(function(htm) {
                        RVTS.next += RVTS.perPage;
                        var evt = new CustomEvent('RVTS_add', {cancelable: true, detail: {htm: htm, addToEnd: true}});
                        window.dispatchEvent(evt);
                        if (!evt.defaultPrevented)
                            RVTS.$thumbs.insertAdjacentHTML('beforeend', htm);
                    })
                    .catch(function() {})
                    .finally(function() {
                        RVTS.loading = 0;
                        if (!RVTS.loadingUp && loaderEl) loaderEl.style.display = 'none';
                        window.dispatchEvent(new CustomEvent('RVTS_loaded', {detail: 1}));
                    });
            },

            checkAutoScroll: function (evt) {
                if (!RVTS.$thumbs) return 0;
                var tBot = RVTS.$thumbs.offsetTop + RVTS.$thumbs.offsetHeight;
                var wBot = window.scrollY + window.innerHeight;
                tBot -= !evt ? 0 : 100; //begin 100 pixels before end
                return tBot <= wBot ? (RVTS.doAutoScroll(), 1) : 0;
            },

            engage: function () {
                RVTS.$thumbs = document.getElementById('thumbnails');
                RVTS.$thumbs.insertAdjacentHTML('afterend',
                    '<div id="ajaxLoader" style="display:none;position:fixed;bottom:32px;right:1%;z-index:999"><img src="' +
                        RVTS.ajaxLoaderImage +
                        '" width="128" height="15" alt="~"></div>'
                );

                if ("#top" == window.location.hash) window.scrollTo(0, 0);

                if (RVTS.$thumbs.offsetHeight < window.innerHeight) RVTS.adjust = 1;
                else if (RVTS.$thumbs.offsetHeight > 2 * window.innerHeight)
                    RVTS.adjust = -1;
                window.addEventListener('scroll', RVTS.checkAutoScroll);
                window.addEventListener('resize', RVTS.checkAutoScroll);
                if (RVTS.checkAutoScroll())
                    window.setTimeout(RVTS.checkAutoScroll, 1500);
            },
        }); //end extend

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                if ("#top" == window.location.hash) window.scrollTo(0, 0);
                window.setTimeout(RVTS.engage, 150);
            });
        } else {
            if ("#top" == window.location.hash) window.scrollTo(0, 0);
            window.setTimeout(RVTS.engage, 150);
        }

        if (window.history.replaceState) {
            var iniStart = RVTS.start;
            window.addEventListener('RVTS_loaded', function handler() {
                window.removeEventListener('RVTS_loaded', handler);
                window.addEventListener('unload', function () {
                    var threshold = Math.max(0, window.scrollY - 60);
                    var elts = RVTS.$thumbs.children;
                    for (var i = 0; i < elts.length; i++) {
                        var r = elts[i].getBoundingClientRect();
                        var offsetTop = r.top + window.scrollY;
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
    })();

// Album / folder infinite scroll
if (window.RVTS_CATS)
    (function () {
        Object.assign(RVTS_CATS, {
            loading: 0,

            doAutoScroll: function () {
                if (RVTS_CATS.loading || RVTS_CATS.next >= RVTS_CATS.total) return;
                var url = RVTS_CATS.ajaxUrlModel
                    .replace('%startcat%', RVTS_CATS.next);
                var loaderEl = document.getElementById('ajaxLoader');
                if (loaderEl) loaderEl.style.display = '';
                RVTS_CATS.loading = 1;
                fetch(url)
                    .then(function(r) { return r.text(); })
                    .then(function(htm) {
                        RVTS_CATS.next += RVTS_CATS.perPage;
                        var tmp = document.createElement('div');
                        tmp.innerHTML = htm;
                        var wrap = null;
                        for (var c = tmp.firstElementChild; c; c = c.nextElementSibling) {
                            if (c.matches('[data-album-grid]')) { wrap = c; break; }
                        }
                        if (!wrap) wrap = tmp.querySelector('[data-album-grid]');
                        var items = Array.from(wrap ? wrap.children : tmp.children);
                        items.forEach(function(item) { RVTS_CATS.$thumbs.appendChild(item); });
                        if (typeof GDThumb !== 'undefined' && typeof GDThumb.build === 'function') {
                            GDThumb.build();
                        }
                    })
                    .catch(function() {})
                    .finally(function() {
                        RVTS_CATS.loading = 0;
                        if (loaderEl) loaderEl.style.display = 'none';
                    });
            },

            checkAutoScroll: function () {
                var tBot = RVTS_CATS.$thumbs.offsetTop + RVTS_CATS.$thumbs.offsetHeight;
                var wBot = window.scrollY + window.innerHeight;
                tBot -= 100;
                return tBot <= wBot ? (RVTS_CATS.doAutoScroll(), 1) : 0;
            },

            engage: function () {
                RVTS_CATS.$thumbs = document.querySelector('[data-album-grid]');
                if (!RVTS_CATS.$thumbs) return;
                RVTS_CATS.$thumbs.insertAdjacentHTML('afterend',
                    '<div id="ajaxLoader" style="display:none;position:fixed;bottom:32px;right:1%;z-index:999"><img src="' +
                        RVTS_CATS.ajaxLoaderImage +
                        '" width="128" height="15" alt="~"></div>'
                );
                window.addEventListener('scroll', RVTS_CATS.checkAutoScroll);
                window.addEventListener('resize', RVTS_CATS.checkAutoScroll);
                if (RVTS_CATS.checkAutoScroll())
                    window.setTimeout(RVTS_CATS.checkAutoScroll, 1500);
            },
        });

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                window.setTimeout(RVTS_CATS.engage, 150);
            });
        } else {
            window.setTimeout(RVTS_CATS.engage, 150);
        }
    })();
