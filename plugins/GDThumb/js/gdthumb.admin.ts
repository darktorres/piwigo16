import ImageLoader from './image.loader.ts';

document.querySelectorAll<HTMLElement>("div.infos").forEach(function (el) {
    setTimeout(function () {
        el.animate([{opacity: 1}, {opacity: 0}], {duration: 600, fill: 'forwards'}).onfinish = function () {
            el.style.display = 'none';
        };
    }, 4000);
});

interface Deferred {
    resolve(): void;
    reject(): void;
    always(fn: () => void): void;
    state(): 'pending' | 'resolved' | 'rejected';
}

const loader = new ImageLoader({ onChanged: loaderChanged });
let pending_next_page: number | null = null;
let last_image_show_time = 0;
let allDoneDfd: Deferred;
let urlDfd: Deferred;

function _makeDeferred(): Deferred {
    let _res: () => void, _rej: () => void, _state: 'pending' | 'resolved' | 'rejected' = 'pending';
    const p = new Promise<void>(function (res, rej) { _res = res; _rej = rej; });
    return {
        resolve(): void { if (_state === 'pending') { _state = 'resolved'; _res(); } },
        reject():  void { if (_state === 'pending') { _state = 'rejected'; _rej(); } },
        always(fn: () => void): void { p.then(fn, fn); },
        state(): 'pending' | 'resolved' | 'rejected' { return _state; }
    };
}

function _setBtn(id: string, disabled: boolean, opacity: number): void {
    const el = document.getElementById(id) as HTMLButtonElement | HTMLInputElement | null;
    if (!el) return;
    (el as HTMLButtonElement).disabled = disabled;
    el.style.opacity = String(opacity);
}

function _setGroup(sel: string, disabled: boolean, opacity: number): void {
    document.querySelectorAll<HTMLButtonElement | HTMLInputElement>(sel).forEach(function (el) {
        el.disabled = disabled;
        el.style.opacity = String(opacity);
    });
}

const gdThumb_start = function (): void {
    allDoneDfd = _makeDeferred();
    urlDfd     = _makeDeferred();

    allDoneDfd.always(function () {
        _setBtn('startLink', false, 1);
        _setGroup('#pauseLink,#stopLink', true, 0.5);
    });

    urlDfd.always(function () {
        if (loader.remaining()=== 0) allDoneDfd.resolve();
    });

    setTimeout(function () {
        const gc = document.getElementById('generate_cache');
        if (gc) (gc).style.display = '';
        _setBtn('startLink', true, 0.5);
        _setGroup('#pauseLink,#stopLink', false, 1);
    }, 0);

    loader.pause(false);
    updateStats();
    getUrls(0);
};

const gdThumb_pause = function (): void {
    loader.pause(!loader.pause());
};

const gdThumb_stop = function (): void {
    loader.clear();
    urlDfd.resolve();
};

document.getElementById('cachebuild')?.addEventListener('click', function (e) {
    e.preventDefault();
    gdThumb_start();
});
document.getElementById('startLink')?.addEventListener('click', gdThumb_start);
document.getElementById('pauseLink')?.addEventListener('click', gdThumb_pause);
document.getElementById('stopLink')?.addEventListener('click', gdThumb_stop);

function getUrls(page_token: number): void {
    const data = new URLSearchParams({ prev_page: String(page_token), max_urls: '500' });
    fetch('admin.php?page=plugin-GDThumb&getMissingDerivative=', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: data
    })
    .then(function (r) { return r.json(); })
    .then(wsData)
    .catch(wsError);
}

function wsData(data: { urls: string[]; next_page?: number }): void {
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

function wsError(): void {
    urlDfd.reject();
}

function updateStats(): void {
    const loadedEl    = document.getElementById('loaded');
    const errorsEl    = document.getElementById('errors');
    const remainingEl = document.getElementById('remaining');
    if (loadedEl)    loadedEl.textContent    = String(loader.loaded);
    if (errorsEl)    errorsEl.textContent     = String(loader.errors);
    if (remainingEl) remainingEl.textContent  = String(loader.remaining());

    if (loader.remaining()=== 0) {
        _setBtn('startLink', false, 1);
        _setGroup('#pauseLink,#stopLink', true, 0.5);
    }
}

function loaderChanged(type: string, img?: HTMLImageElement): void {
    updateStats();
    if (img) {
        if (type === "load") {
            const now = Date.now();
            if (now - last_image_show_time > 3000) {
                last_image_show_time = now;
                const h   = img.height;
                const url = img.src;
                const wrap = document.getElementById('feedbackWrap');
                const fimg = document.getElementById('feedbackImg') as HTMLImageElement | null;
                if (wrap && fimg) {
                    wrap.style.transition = 'opacity 0.25s';
                    wrap.style.opacity = '0';
                    setTimeout(function () {
                        last_image_show_time = Date.now();
                        if (h > 300) fimg.setAttribute('height', '300');
                        else fimg.removeAttribute('height');
                        fimg.setAttribute('src', url);
                        wrap.style.opacity = '1';
                        setTimeout(function () { wrap.style.transition = ''; }, 250);
                    }, 250);
                }
            }
        } else {
            const errList = document.getElementById('errorList');
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
        loader.remaining()=== 0 &&
        (urlDfd.state() === "resolved" || urlDfd.state() === "rejected")
    ) {
        allDoneDfd.resolve();
    }
}
