export function initRVTS(RVTS) {
    if (RVTS.start > 0) {
        const fEl = document.querySelector(".navigationBar A[rel=first]");
        const upDiv = document.createElement('div');
        upDiv.id = 'rvtsUp';
        upDiv.style.cssText = 'text-align:center;font-size:120%;margin:10px';
        if (fEl) {
            const firstA = document.createElement('a');
            firstA.href = fEl.href;
            firstA.textContent = fEl.textContent;
            upDiv.appendChild(firstA);
            upDiv.appendChild(document.createTextNode(' | '));
        }
        const prevA = document.createElement('a');
        prevA.href = '#';
        prevA.textContent = RVTS.prevMsg;
        prevA.addEventListener('click', function(e) { e.preventDefault(); RVTS.loadUp(); });
        upDiv.appendChild(prevA);
        const thumbsContEl = document.getElementById('thumbnails');
        if (thumbsContEl) thumbsContEl.insertAdjacentElement('beforebegin', upDiv);
    }

    Object.assign(RVTS, {
        loading: 0,
        loadingUp: 0,
        adjust: 0,

        loadUp: function () {
            if (RVTS.loadingUp || RVTS.start <= 0) return;
            let newStart = RVTS.start - RVTS.perPage;
            let reqCount = RVTS.perPage;
            if (newStart < 0) {
                reqCount += newStart;
                newStart = 0;
            }
            let url = RVTS.ajaxUrlModel
                .replace("%start%", newStart)
                .replace("%per%", reqCount);
            const loaderEl = document.getElementById('ajaxLoader');
            if (loaderEl) loaderEl.style.display = '';
            RVTS.loadingUp = 1;
            fetch(url)
                .then(function(r) { return r.text(); })
                .then(function(htm) {
                    RVTS.start = newStart;
                    const evt = new CustomEvent('RVTS_add', {cancelable: true, detail: {htm: htm, addToEnd: false}});
                    window.dispatchEvent(evt);
                    if (!evt.defaultPrevented) {
                        const tmp = document.createElement('div');
                        tmp.innerHTML = htm;
                        const wrap = tmp.querySelector('#thumbnails');
                        if (!wrap) return;
                        const items = Array.from(wrap.children);
                        const frag = document.createDocumentFragment();
                        items.forEach(function(item) { frag.appendChild(item); });
                        RVTS.$thumbs.insertBefore(frag, RVTS.$thumbs.firstChild);
                    }
                    if (RVTS.start <= 0) {
                        const rvtsUpEl = document.getElementById('rvtsUp');
                        if (rvtsUpEl) rvtsUpEl.remove();
                    }
                })
                .catch(function() {})
                .finally(function() {
                    RVTS.loadingUp = 0;
                    if (!RVTS.loading && loaderEl) loaderEl.style.display = 'none';
                    window.dispatchEvent(new CustomEvent('RVTS_loaded', {detail: {down: false}}));
                });
        },

        doAutoScroll: function () {
            if (RVTS.loading || RVTS.next >= RVTS.total) return;
            let url = RVTS.ajaxUrlModel
                .replace("%start%", RVTS.next)
                .replace("%per%", RVTS.perPage);
            if (RVTS.adjust) {
                url += "&adj=" + RVTS.adjust;
                RVTS.adjust = 0;
            }
            const loaderEl = document.getElementById('ajaxLoader');
            if (loaderEl) loaderEl.style.display = '';
            RVTS.loading = 1;
            fetch(url)
                .then(function(r) { return r.text(); })
                .then(function(htm) {
                    RVTS.next += RVTS.perPage;
                    const evt = new CustomEvent('RVTS_add', {cancelable: true, detail: {htm: htm, addToEnd: true}});
                    window.dispatchEvent(evt);
                    if (!evt.defaultPrevented) {
                        const tmp = document.createElement('div');
                        tmp.innerHTML = htm;
                        const wrap = tmp.querySelector('#thumbnails');
                        if (!wrap) return;
                        const items = Array.from(wrap.children);
                        const frag = document.createDocumentFragment();
                        items.forEach(function(item) { frag.appendChild(item); });
                        RVTS.$thumbs.appendChild(frag);
                    }
                })
                .catch(function() {})
                .finally(function() {
                    RVTS.loading = 0;
                    if (!RVTS.loadingUp && loaderEl) loaderEl.style.display = 'none';
                    window.dispatchEvent(new CustomEvent('RVTS_loaded', {detail: {down: true}}));
                });
        },

        checkAutoScroll: function (evt) {
            if (!RVTS.$thumbs) return 0;
            const scrollEl = RVTS.$scrollEl || window;
            let tBot, wBot;
            if (scrollEl === window) {
                tBot = RVTS.$thumbs.offsetTop + RVTS.$thumbs.offsetHeight;
                wBot = window.scrollY + window.innerHeight;
            } else {
                tBot = scrollEl.scrollHeight;
                wBot = scrollEl.scrollTop + scrollEl.clientHeight;
            }
            tBot -= !evt ? 0 : 100;
            return tBot <= wBot ? (RVTS.doAutoScroll(), 1) : 0;
        },

        engage: function () {
            RVTS.$thumbs = document.getElementById('thumbnails') || document.querySelector('.thumbnails');
            if (!RVTS.$thumbs) return;
            RVTS.$thumbs.insertAdjacentHTML('afterend',
                '<div id="ajaxLoader" style="display:none;position:fixed;bottom:32px;right:1%;z-index:999"><img src="' +
                    RVTS.ajaxLoaderImage +
                    '" width="128" height="15" alt="~"></div>'
            );

            RVTS.$scrollEl = document.querySelector('[data-rvts-scroll]') || window;

            if ("#top" == window.location.hash) RVTS.$scrollEl.scrollTo(0, 0);

            const viewH = RVTS.$scrollEl === window ? window.innerHeight : RVTS.$scrollEl.clientHeight;
            if (RVTS.$thumbs.offsetHeight < viewH) RVTS.adjust = 1;
            else if (RVTS.$thumbs.offsetHeight > 2 * viewH)
                RVTS.adjust = -1;
            RVTS.$scrollEl.addEventListener('scroll', RVTS.checkAutoScroll);
            window.addEventListener('resize', RVTS.checkAutoScroll);
            if (RVTS.checkAutoScroll())
                window.setTimeout(RVTS.checkAutoScroll, 1500);
        },
    });

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
        const iniStart = RVTS.start;
        window.addEventListener('RVTS_loaded', function handler() {
            window.removeEventListener('RVTS_loaded', handler);
            window.addEventListener('pagehide', function () {
                const scrollEl = RVTS.$scrollEl || window;
                const currentScrollY = scrollEl === window ? window.scrollY : scrollEl.scrollTop;
                const threshold = Math.max(0, currentScrollY - 60);
                const elts = RVTS.$thumbs.children;
                for (let i = 0; i < elts.length; i++) {
                    const r = elts[i].getBoundingClientRect();
                    const offsetTop = r.top + currentScrollY;
                    if (offsetTop >= threshold) {
                        const start = RVTS.start + i;
                        const delta = start - iniStart;
                        if (delta < 0 || delta >= RVTS.perPage) {
                            let url = start
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
                            } catch (_e) {}
                        }
                        break;
                    }
                }
            });
        });
    }
}

export function initRVTS_CATS(RVTS_CATS) {
    Object.assign(RVTS_CATS, {
        loading: 0,

        doAutoScroll: function () {
            if (RVTS_CATS.loading || RVTS_CATS.next >= RVTS_CATS.total) return;
            const url = RVTS_CATS.ajaxUrlModel
                .replace('%startcat%', RVTS_CATS.next);
            const loaderEl = document.getElementById('ajaxLoader');
            if (loaderEl) loaderEl.style.display = '';
            RVTS_CATS.loading = 1;
            fetch(url)
                .then(function(r) { return r.text(); })
                .then(function(htm) {
                    RVTS_CATS.next += RVTS_CATS.perPage;
                    const tmp = document.createElement('div');
                    tmp.innerHTML = htm;
                    let wrap = null;
                    for (let c = tmp.firstElementChild; c; c = c.nextElementSibling) {
                        if (c.matches('[data-album-grid]')) { wrap = c; break; }
                    }
                    if (!wrap) wrap = tmp.querySelector('[data-album-grid]');
                    const items = Array.from(wrap ? wrap.children : tmp.children);
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
            const scrollEl = RVTS_CATS.$scrollEl || window;
            let tBot = scrollEl === window
                ? RVTS_CATS.$thumbs.offsetTop + RVTS_CATS.$thumbs.offsetHeight
                : scrollEl.scrollHeight;
            const wBot = scrollEl === window
                ? window.scrollY + window.innerHeight
                : scrollEl.scrollTop + scrollEl.clientHeight;
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
            RVTS_CATS.$scrollEl = document.querySelector('[data-rvts-scroll]') || window;
            RVTS_CATS.$scrollEl.addEventListener('scroll', RVTS_CATS.checkAutoScroll);
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
}
