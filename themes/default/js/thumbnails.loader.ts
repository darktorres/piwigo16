// Configuration is read from <body data-error-icon="..." data-max-requests="...">
// (set by themes/default/template/header.tpl). Falling back to ambient globals
// for any plugin that still defines `error_icon` / `max_requests` inline.
declare var error_icon: string;

const errorIconFromBody = document.body.dataset['errorIcon'];
const maxRequestsFromBody = document.body.dataset['maxRequests'];

if (errorIconFromBody && typeof error_icon === 'undefined') {
    (window as any).error_icon = errorIconFromBody;
}
if (typeof max_requests === 'undefined') {
    (window as any).max_requests = maxRequestsFromBody ? Number(maxRequestsFromBody) : 3;
}

class AjaxQueue {
    private pending: Array<() => Promise<void>> = [];
    private running = 0;
    private max: number;

    constructor(max: number) { this.max = max; }

    add(fn: () => Promise<void>): void {
        this.pending.push(fn);
        this.process();
    }

    private process(): void {
        while (this.running < this.max && this.pending.length > 0) {
            const fn = this.pending.shift()!;
            this.running++;
            fn().finally(() => { this.running--; this.process(); });
        }
    }
}

const thumbnails_queue = new AjaxQueue(max_requests ?? 3);

function add_thumbnail_to_queue(img: HTMLImageElement, loop: number): void {
    thumbnails_queue.add(async () => {
        document.querySelectorAll<HTMLElement>('.loader').forEach(el => { el.style.display = ''; });
        try {
            const r = await fetch(img.dataset['src']! + '&ajaxload=true');
            const result = await r.json() as { url: string };
            img.src = result.url;
        } catch {
            if (loop < 3) add_thumbnail_to_queue(img, ++loop);
            if (typeof error_icon !== 'undefined') img.src = error_icon;
        } finally {
            document.querySelectorAll<HTMLElement>('.loader').forEach(el => { el.style.display = 'none'; });
        }
    });
}

function pwg_ajax_thumbnails_loader(): void {
    document.querySelectorAll<HTMLImageElement>('img[data-src]').forEach(img => {
        add_thumbnail_to_queue(img, 0);
    });
}

document.addEventListener('DOMContentLoaded', pwg_ajax_thumbnails_loader);

export {};
