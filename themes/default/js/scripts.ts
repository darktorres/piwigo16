export function phpWGOpenWindow(theURL: string, winName: string, features: string): void {
    const img = new Image();
    let width: number, height: number;
    let newWin: Window | null = null;
    img.src = theURL;
    if (img.complete) {
        width = img.width + 40;
        height = img.height + 40;
    } else {
        width = 640;
        height = 480;
        img.onload = function () {
            newWin?.resizeTo(img.width + 50, img.height + 100);
        };
    }
    newWin = window.open(
        theURL,
        winName,
        features + ",left=2,top=1,width=" + width + ",height=" + height,
    );
}

export function popuphelp(url: string): void {
    window.open(
        url,
        "dc_popup",
        "alwaysRaised=yes,dependent=yes,toolbar=no,height=420,width=500,menubar=no,resizable=yes,scrollbars=yes,status=no",
    );
}

interface PwgWSOptions {
    method?: string;
    async?: boolean;
    onFailure?: ((code: number, text: string) => void) | null;
    onSuccess?: ((result: unknown) => void) | null;
}

export class PwgWS {
    private urlRoot: string;
    private options: Required<PwgWSOptions>;
    private xhr: XMLHttpRequest | null = null;

    constructor(urlRoot: string) {
        this.urlRoot = urlRoot;
        this.options = { method: "GET", async: true, onFailure: null, onSuccess: null };
    }

    callService(method: string, parameters?: Record<string, unknown>, options?: PwgWSOptions): void {
        if (options) Object.assign(this.options, options);

        this.xhr = new XMLHttpRequest();
        this.xhr.onreadystatechange = () => this.onStateChange();

        let url = this.urlRoot + "ws.php?format=json&method=" + method;
        let body = "";

        if (parameters) {
            for (const prop in parameters) {
                const val = parameters[prop];
                if (Array.isArray(val)) {
                    for (const item of val) body += prop + "[]=" + encodeURIComponent(String(item)) + "&";
                } else {
                    body += prop + "=" + encodeURIComponent(String(val)) + "&";
                }
            }
        }

        if (this.options.method !== "POST") {
            url += "&" + body;
            body = "";
        }
        this.xhr.open(this.options.method, url, this.options.async);
        if (this.options.method === "POST")
            this.xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        try {
            this.xhr.send(body || null);
        } catch (e) {
            this.error(0, (e as Error).message);
        }
    }

    private onStateChange(): void {
        if (this.xhr?.readyState === 4) {
            try {
                this.respondToReadyState();
            } finally {
                this.cleanup();
            }
        }
    }

    private error(httpCode: number, text: string): void {
        this.options.onFailure?.(httpCode, text);
        this.cleanup();
    }

    private respondToReadyState(): void {
        const xhr = this.xhr!;
        if (xhr.status === 200) {
            let resp: { stat?: string; result: unknown; err?: number; message?: string };
            try {
                resp = JSON.parse(xhr.responseText) as { stat?: string; result: unknown; err?: number; message?: string };
            } catch (e) {
                this.error(200, (e as Error).message + "\n" + xhr.responseText.substring(0, 512));
                return;
            }
            if (resp.stat == null) this.error(200, "Invalid response");
            else if (resp.stat === "ok") this.options.onSuccess?.(resp.result);
            else this.error(200, (resp.err ?? '') + " " + (resp.message ?? ''));
        } else {
            this.error(xhr.status, xhr.statusText);
        }
    }

    private cleanup(): void {
        if (this.xhr) this.xhr.onreadystatechange = null;
        this.xhr = null;
        this.options.onFailure = this.options.onSuccess = null;
    }
}

export function pwgAddEventListener(elem: Element, evt: string, fn: EventListener): void {
    elem.addEventListener(evt, fn, false);
}

export function setupPwgOpenWindow(): void {
    document.addEventListener('click', function (e) {
        const link = (e.target as Element).closest<HTMLElement>('[data-action="pwgOpenWindow"]');
        if (link) {
            e.preventDefault();
            phpWGOpenWindow(link.dataset['url'] ?? '', 'xxx', link.dataset['features'] ?? '');
        }
    });
}

export function setupPopuphelp(): void {
    document.addEventListener('click', function (e) {
        const link = (e.target as Element).closest<HTMLAnchorElement>('[data-action="pwgPopupHelp"]');
        if (link) {
            e.preventDefault();
            popuphelp(link.href);
        }
    });
}
