import { config } from './config';

function phpWGOpenWindow(theURL: string, winName: string, features: string): void {
    const img = new Image();
    img.src = theURL;
    let width: number, height: number;
    if (img.complete) {
        width = img.width + 40;
        height = img.height + 40;
    } else {
        width = 640;
        height = 480;
        img.onload = function () {
            newWin.resizeTo(img.width + 50, img.height + 100);
        };
    }
    const newWin = window.open(
        theURL,
        winName,
        features + ',left=2,top=1,width=' + width + ',height=' + height
    )!;
    (window as any).newWin = newWin;
}

function popuphelp(url: string): void {
    window.open(
        url,
        'dc_popup',
        'alwaysRaised=yes,dependent=yes,toolbar=no,height=420,width=500,menubar=no,resizable=yes,scrollbars=yes,status=no'
    );
}

function pwgBind(
    object: object,
    method: (...args: unknown[]) => unknown,
    ...boundArgs: unknown[]
): (...callArgs: unknown[]) => unknown {
    return function (...callArgs: unknown[]) {
        return method.apply(object, boundArgs.concat(callArgs));
    };
}

class PwgWS {
    urlRoot: string;
    options: {
        method: string;
        async: boolean;
        onFailure: ((code: number, text: string) => void) | null;
        onSuccess: ((result: unknown) => void) | null;
    };
    xhr: XMLHttpRequest | null = null;

    constructor(urlRoot: string) {
        this.urlRoot = urlRoot;
        this.options = {
            method: 'GET',
            async: true,
            onFailure: null,
            onSuccess: null,
        };
    }

    callService(
        method: string,
        parameters: Record<string, unknown> | null,
        options?: Partial<typeof this.options>
    ): void {
        if (options) {
            for (const prop in options) {
                (this.options as any)[prop] = (options as any)[prop];
            }
        }
        this.xhr = new XMLHttpRequest();
        this.xhr.onreadystatechange = pwgBind(this, this.onStateChange) as () => void;

        let url = config.wsUrl + 'format=json&method=' + method;
        let body = '';
        if (parameters) {
            for (const prop in parameters) {
                const val = parameters[prop];
                if (typeof val === 'object' && val && Array.isArray(val)) {
                    for (let i = 0; i < (val as unknown[]).length; i++) {
                        body +=
                            prop + '[]=' + encodeURIComponent(String((val as unknown[])[i])) + '&';
                    }
                } else {
                    body += prop + '=' + encodeURIComponent(String(val)) + '&';
                }
            }
        }

        if (this.options.method !== 'POST') {
            url += '&' + body;
            body = '';
        }
        this.xhr.open(this.options.method, url, this.options.async);
        if (this.options.method === 'POST') {
            this.xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        }
        try {
            this.xhr.send(body || null);
        } catch (e: unknown) {
            this.error(0, (e as Error).message);
        }
    }

    onStateChange(): void {
        const readyState = this.xhr?.readyState;
        if (readyState === 4) {
            try {
                this.respondToReadyState(readyState);
            } finally {
                this.cleanup();
            }
        }
    }

    error(httpCode: number, text: string): void {
        this.options.onFailure?.(httpCode, text);
        this.cleanup();
    }

    respondToReadyState(readyState: number): void {
        const xhr = this.xhr!;
        if (readyState === 4 && xhr.status === 200) {
            let resp: any;
            try {
                resp = JSON.parse(xhr.responseText);
            } catch (e: unknown) {
                this.error(200, (e as Error).message + '\n' + xhr.responseText.substring(0, 512));
                return;
            }
            if (resp != null) {
                if (resp.stat == null) {
                    this.error(200, 'Invalid response');
                } else if (resp.stat === 'ok') {
                    this.options.onSuccess?.(resp.result);
                } else {
                    this.error(200, resp.err + ' ' + resp.message);
                }
            }
        }
        if (readyState === 4 && xhr.status !== 200) {
            this.error(xhr.status, xhr.statusText);
        }
    }

    cleanup(): void {
        if (this.xhr) this.xhr.onreadystatechange = null;
        this.xhr = null;
        this.options.onFailure = this.options.onSuccess = null;
    }
}

function pwgAddEventListener(elem: Element | Window, evt: string, fn: EventListener): void {
    elem.addEventListener(evt, fn, false);
}

function changeImgSrc(url: string, typeSave: string, typeMap: string): void {
    const theImg = document.getElementById('theMainImage') as HTMLImageElement | null;
    if (theImg) {
        theImg.removeAttribute('width');
        theImg.removeAttribute('height');
        theImg.src = url;
        theImg.useMap = '#map' + typeMap;
    }
    document.querySelectorAll<HTMLElement>('#derivativeSwitchBox .switchCheck').forEach((el) => {
        el.style.visibility = 'hidden';
    });
    const checked = document.getElementById('derivativeChecked' + typeMap);
    if (checked) checked.style.visibility = 'visible';
    const cookiePath =
        document.querySelector<HTMLElement>('#derivativeSwitchBox')?.dataset['cookiePath'] ?? '/';
    document.cookie = 'picture_deriv=' + typeSave + ';path=' + cookiePath;
}

document.addEventListener('DOMContentLoaded', () => {
    // Derivative size switcher links
    document.querySelectorAll<HTMLAnchorElement>('a.derivative-switch-item').forEach((el) => {
        el.addEventListener('click', (e) => {
            e.preventDefault();
            changeImgSrc(el.href, el.dataset['typeSave']!, el.dataset['typeMap']!);
        });
    });

    // Generic confirm-on-click: <a data-confirm="Are you sure?" href="...">
    document.querySelectorAll<HTMLElement>('[data-confirm]').forEach((el) => {
        el.addEventListener('click', (e) => {
            const msg = el.getAttribute('data-confirm') || '';
            if (!confirm(msg)) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });

    // Open in named window: <a href data-window-open-name=NAME data-window-open-features=FEATURES>
    document.querySelectorAll<HTMLAnchorElement>('a[data-window-open-name]').forEach((a) => {
        a.addEventListener('click', (e) => {
            e.preventDefault();
            const name = a.getAttribute('data-window-open-name') || '';
            const features = a.getAttribute('data-window-open-features') || '';
            window.open(a.href, name, features);
        });
    });

    // Open as popup help window: <a href data-popuphelp>
    document.querySelectorAll<HTMLAnchorElement>('a[data-popuphelp]').forEach((a) => {
        a.addEventListener('click', (e) => {
            e.preventDefault();
            popuphelp(a.href);
        });
    });

    // Close window: <a data-close-window>
    document.querySelectorAll<HTMLElement>('[data-close-window]').forEach((el) => {
        el.addEventListener('click', (e) => {
            e.preventDefault();
            window.close();
        });
    });

    // Download switchbox: remove href so it acts as a toggle trigger, not a download link
    const downloadSwitchBox = document.querySelector<HTMLElement>('[data-disable-href]');
    if (downloadSwitchBox) {
        const targetId = downloadSwitchBox.dataset['disableHref']!;
        document.getElementById(targetId)?.removeAttribute('href');
    }

    // Caddie button
    const caddieBtn = document.getElementById('cmdAddToCaddie') as HTMLAnchorElement | null;
    if (caddieBtn) {
        caddieBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (caddieBtn.dataset['loading']) return;
            caddieBtn.dataset['loading'] = '1';
            const rootUrl = caddieBtn.dataset['rootUrl']!;
            const imageId = Number(caddieBtn.dataset['imageId']!);
            const y = new PwgWS(rootUrl);
            y.callService(
                'pwg.caddie.add',
                { image_id: imageId },
                {
                    onFailure: (num: number, text: string) => {
                        alert(num + ' ' + text);
                        location.href = caddieBtn.href;
                    },
                    onSuccess: () => {
                        delete caddieBtn.dataset['loading'];
                    },
                }
            );
        });
    }
});

// Expose to window for inline-script callers
(window as any).phpWGOpenWindow = phpWGOpenWindow;
(window as any).popuphelp = popuphelp;
(window as any).pwgBind = pwgBind;
(window as any).PwgWS = PwgWS;
(window as any).pwgAddEventListener = pwgAddEventListener;

export {};
