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
        if (options) {
            Object.assign(this.options, options);
        }
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
            let resp: { stat: string; result: unknown; err?: number; message?: string } | null = null;
            try {
                resp = JSON.parse(xhr.responseText) as { stat: string; result: unknown; err?: number; message?: string };
            } catch (e) {
                this.error(200, (e as Error).message + "\n" + xhr.responseText.substring(0, 512));
                return;
            }
            if (resp != null) {
                if (resp.stat == null) this.error(200, "Invalid response");
                else if (resp.stat === "ok") this.options.onSuccess?.(resp.result);
                else this.error(200, (resp.err ?? '') + " " + (resp.message ?? ''));
            }
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
