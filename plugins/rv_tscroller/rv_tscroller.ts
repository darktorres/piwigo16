interface RVTSConfig {
    ajaxUrlModel: string;
    start: number;
    perPage: number;
    next: number;
    total: number;
    urlModel: string;
    moreMsg: string;
    prevMsg: string;
    ajaxLoaderImage: string;
    loading: number;
    loadingUp: number;
    adjust: number;
    $thumbs: Element | null;
    $scrollEl: Element | Window;
    loadUp(): void;
    doAutoScroll(): void;
    checkAutoScroll(evt?: Event): number;
    engage(): void;
}

interface RVTSCATSConfig {
    ajaxUrlModel: string;
    start: number;
    perPage: number;
    next: number;
    total: number;
    ajaxLoaderImage: string;
    loading: number;
    $thumbs: Element | null;
    $scrollEl: Element | Window;
    doAutoScroll(): void;
    checkAutoScroll(): number;
    engage(): void;
}

declare const GDThumb: { build: () => void } | undefined;

export function initRVTS(RVTS: Partial<RVTSConfig> & Pick<RVTSConfig, 'ajaxUrlModel' | 'start' | 'perPage' | 'next' | 'total' | 'urlModel' | 'prevMsg' | 'ajaxLoaderImage'>): void {
    const cfg = RVTS as RVTSConfig;

    if (cfg.start > 0) {
        const fEl = document.querySelector<HTMLAnchorElement>(".navigationBar A[rel=first]");
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
        prevA.textContent = cfg.prevMsg;
        prevA.addEventListener('click', function (e) { e.preventDefault(); cfg.loadUp(); });
        upDiv.appendChild(prevA);
        const thumbsContEl = document.getElementById('thumbnails');
        if (thumbsContEl) thumbsContEl.insertAdjacentElement('beforebegin', upDiv);
    }

    Object.assign(cfg, {
        loading: 0,
        loadingUp: 0,
        adjust: 0,

        loadUp(): void {
            if (cfg.loadingUp || cfg.start <= 0) return;
            let newStart = cfg.start - cfg.perPage;
            let reqCount = cfg.perPage;
            if (newStart < 0) {
                reqCount += newStart;
                newStart = 0;
            }
            let url = cfg.ajaxUrlModel
                .replace("%start%", String(newStart))
                .replace("%per%", String(reqCount));
            const loaderEl = document.getElementById('ajaxLoader') as HTMLElement | null;
            if (loaderEl) loaderEl.style.display = '';
            cfg.loadingUp = 1;
            fetch(url)
                .then(function (r) { return r.text(); })
                .then(function (htm) {
                    cfg.start = newStart;
                    const evt = new CustomEvent('RVTS_add', {cancelable: true, detail: {htm, addToEnd: false}});
                    window.dispatchEvent(evt);
                    if (!evt.defaultPrevented) {
                        const tmp = document.createElement('div');
                        tmp.innerHTML = htm;
                        const wrap = tmp.querySelector('#thumbnails');
                        if (!wrap) return;
                        const items = Array.from(wrap.children);
                        const frag = document.createDocumentFragment();
                        items.forEach(function (item) { frag.appendChild(item); });
                        if (cfg.$thumbs) cfg.$thumbs.insertBefore(frag, cfg.$thumbs.firstChild);
                    }
                    if (cfg.start <= 0) {
                        const rvtsUpEl = document.getElementById('rvtsUp');
                        if (rvtsUpEl) rvtsUpEl.remove();
                    }
                })
                .catch(function () {})
                .finally(function () {
                    cfg.loadingUp = 0;
                    if (!cfg.loading && loaderEl) loaderEl.style.display = 'none';
                    window.dispatchEvent(new CustomEvent('RVTS_loaded', {detail: {down: false}}));
                });
        },

        doAutoScroll(): void {
            if (cfg.loading || cfg.next >= cfg.total) return;
            let url = cfg.ajaxUrlModel
                .replace("%start%", String(cfg.next))
                .replace("%per%", String(cfg.perPage));
            if (cfg.adjust) {
                url += "&adj=" + cfg.adjust;
                cfg.adjust = 0;
            }
            const loaderEl = document.getElementById('ajaxLoader') as HTMLElement | null;
            if (loaderEl) loaderEl.style.display = '';
            cfg.loading = 1;
            fetch(url)
                .then(function (r) { return r.text(); })
                .then(function (htm) {
                    cfg.next += cfg.perPage;
                    const evt = new CustomEvent('RVTS_add', {cancelable: true, detail: {htm, addToEnd: true}});
                    window.dispatchEvent(evt);
                    if (!evt.defaultPrevented) {
                        const tmp = document.createElement('div');
                        tmp.innerHTML = htm;
                        const wrap = tmp.querySelector('#thumbnails');
                        if (!wrap) return;
                        const items = Array.from(wrap.children);
                        const frag = document.createDocumentFragment();
                        items.forEach(function (item) { frag.appendChild(item); });
                        if (cfg.$thumbs) cfg.$thumbs.appendChild(frag);
                    }
                })
                .catch(function () {})
                .finally(function () {
                    cfg.loading = 0;
                    if (!cfg.loadingUp && loaderEl) loaderEl.style.display = 'none';
                    window.dispatchEvent(new CustomEvent('RVTS_loaded', {detail: {down: true}}));
                });
        },

        checkAutoScroll(evt?: Event): number {
            if (!cfg.$thumbs) return 0;
            const scrollEl = cfg.$scrollEl || window;
            let tBot: number, wBot: number;
            if (scrollEl === window) {
                tBot = (cfg.$thumbs as HTMLElement).offsetTop + (cfg.$thumbs as HTMLElement).offsetHeight;
                wBot = window.scrollY + window.innerHeight;
            } else {
                tBot = (scrollEl as HTMLElement).scrollHeight;
                wBot = (scrollEl as HTMLElement).scrollTop + (scrollEl as HTMLElement).clientHeight;
            }
            tBot -= !evt ? 0 : 100;
            return tBot <= wBot ? (cfg.doAutoScroll(), 1) : 0;
        },

        engage(): void {
            cfg.$thumbs = document.getElementById('thumbnails') || document.querySelector('.thumbnails');
            if (!cfg.$thumbs) return;
            cfg.$thumbs.insertAdjacentHTML('afterend',
                '<div id="ajaxLoader" style="display:none;position:fixed;bottom:32px;right:1%;z-index:999"><img src="' +
                    cfg.ajaxLoaderImage +
                    '" width="128" height="15" alt="~"></div>'
            );

            cfg.$scrollEl = document.querySelector('[data-rvts-scroll]') || window;

            if ("#top" == window.location.hash) (cfg.$scrollEl as Window).scrollTo(0, 0);

            const viewH = cfg.$scrollEl === window ? window.innerHeight : (cfg.$scrollEl as HTMLElement).clientHeight;
            if ((cfg.$thumbs as HTMLElement).offsetHeight < viewH) cfg.adjust = 1;
            else if ((cfg.$thumbs as HTMLElement).offsetHeight > 2 * viewH)
                cfg.adjust = -1;
            cfg.$scrollEl.addEventListener('scroll', cfg.checkAutoScroll);
            window.addEventListener('resize', cfg.checkAutoScroll);
            if (cfg.checkAutoScroll())
                window.setTimeout(cfg.checkAutoScroll, 1500);
        },
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            if ("#top" == window.location.hash) window.scrollTo(0, 0);
            window.setTimeout(cfg.engage, 150);
        });
    } else {
        if ("#top" == window.location.hash) window.scrollTo(0, 0);
        window.setTimeout(cfg.engage, 150);
    }

    if (window.history.replaceState) {
        const iniStart = cfg.start;
        window.addEventListener('RVTS_loaded', function handler() {
            window.removeEventListener('RVTS_loaded', handler);
            window.addEventListener('pagehide', function () {
                const scrollEl = cfg.$scrollEl || window;
                const currentScrollY = scrollEl === window ? window.scrollY : (scrollEl as HTMLElement).scrollTop;
                const threshold = Math.max(0, currentScrollY - 60);
                const elts = cfg.$thumbs ? cfg.$thumbs.children : [];
                for (let i = 0; i < elts.length; i++) {
                    const r = elts[i].getBoundingClientRect();
                    const offsetTop = r.top + currentScrollY;
                    if (offsetTop >= threshold) {
                        const start = cfg.start + i;
                        const delta = start - iniStart;
                        if (delta < 0 || delta >= cfg.perPage) {
                            let url = start
                                ? cfg.urlModel.replace("%start%", String(start))
                                : cfg.urlModel.replace(
                                      "/start-%start%",
                                      "",
                                  );
                            try {
                                window.history.replaceState(
                                    null,
                                    "",
                                    url + "#top",
                                );
                            } catch (_e) { /* ignore history.replaceState errors */ }
                        }
                        break;
                    }
                }
            });
        });
    }
}

export function initRVTS_CATS(RVTS_CATS: Partial<RVTSCATSConfig> & Pick<RVTSCATSConfig, 'ajaxUrlModel' | 'start' | 'perPage' | 'next' | 'total' | 'ajaxLoaderImage'>): void {
    const cfg = RVTS_CATS as RVTSCATSConfig;

    Object.assign(cfg, {
        loading: 0,

        doAutoScroll(): void {
            if (cfg.loading || cfg.next >= cfg.total) return;
            const url = cfg.ajaxUrlModel
                .replace('%startcat%', String(cfg.next));
            const loaderEl = document.getElementById('ajaxLoader') as HTMLElement | null;
            if (loaderEl) loaderEl.style.display = '';
            cfg.loading = 1;
            fetch(url)
                .then(function (r) { return r.text(); })
                .then(function (htm) {
                    cfg.next += cfg.perPage;
                    const tmp = document.createElement('div');
                    tmp.innerHTML = htm;
                    let wrap: Element | null = null;
                    for (let c = tmp.firstElementChild; c; c = c.nextElementSibling) {
                        if (c.matches('[data-album-grid]')) { wrap = c; break; }
                    }
                    if (!wrap) wrap = tmp.querySelector('[data-album-grid]');
                    const items = Array.from(wrap ? wrap.children : tmp.children);
                    items.forEach(function (item) { if (cfg.$thumbs) cfg.$thumbs.appendChild(item); });
                    if (typeof GDThumb !== 'undefined' && typeof GDThumb.build === 'function') {
                        GDThumb.build();
                    }
                })
                .catch(function () {})
                .finally(function () {
                    cfg.loading = 0;
                    if (loaderEl) loaderEl.style.display = 'none';
                });
        },

        checkAutoScroll(): number {
            const scrollEl = cfg.$scrollEl || window;
            let tBot = scrollEl === window
                ? (cfg.$thumbs as HTMLElement).offsetTop + (cfg.$thumbs as HTMLElement).offsetHeight
                : (scrollEl as HTMLElement).scrollHeight;
            const wBot = scrollEl === window
                ? window.scrollY + window.innerHeight
                : (scrollEl as HTMLElement).scrollTop + (scrollEl as HTMLElement).clientHeight;
            tBot -= 100;
            return tBot <= wBot ? (cfg.doAutoScroll(), 1) : 0;
        },

        engage(): void {
            cfg.$thumbs = document.querySelector('[data-album-grid]');
            if (!cfg.$thumbs) return;
            cfg.$thumbs.insertAdjacentHTML('afterend',
                '<div id="ajaxLoader" style="display:none;position:fixed;bottom:32px;right:1%;z-index:999"><img src="' +
                    cfg.ajaxLoaderImage +
                    '" width="128" height="15" alt="~"></div>'
            );
            cfg.$scrollEl = document.querySelector('[data-rvts-scroll]') || window;
            cfg.$scrollEl.addEventListener('scroll', cfg.checkAutoScroll);
            window.addEventListener('resize', cfg.checkAutoScroll);
            if (cfg.checkAutoScroll())
                window.setTimeout(cfg.checkAutoScroll, 1500);
        },
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            window.setTimeout(cfg.engage, 150);
        });
    } else {
        window.setTimeout(cfg.engage, 150);
    }
}
