interface ImageLoaderOptions {
    maxRequests?: number;
    onChanged?: (type: string, img?: HTMLImageElement) => void;
}

interface PoolImage extends HTMLImageElement {
    _loaderHandler: ((e: Event) => void) | null;
}

class ImageLoader {
    loaded = 0;
    errors = 0;
    errorEma = 0;
    paused = false;
    current: PoolImage[] = [];
    queue: string[] = [];
    pool: PoolImage[] = [];
    opts: Required<ImageLoaderOptions>;

    constructor(opts?: ImageLoaderOptions) {
        this.opts = Object.assign(
            {
                maxRequests: 30,
                onChanged(): void {},
            },
            opts || {},
        );
    }

    remaining(): number {
        return this.current.length + this.queue.length;
    }

    add(urls: string[]): void {
        this.queue = this.queue.concat(urls);
        this._fireChanged("add");
        this._checkQueue();
    }

    clear(): void {
        this.queue.length = 0;
        while (this.current.length) {
            const img = this.current.pop();
            if (img && img._loaderHandler) {
                img.removeEventListener('load',  img._loaderHandler);
                img.removeEventListener('error', img._loaderHandler);
                img.removeEventListener('abort', img._loaderHandler);
                img._loaderHandler = null;
            }
        }
        this.loaded = this.errors = this.errorEma = 0;
    }

    pause(val?: boolean): boolean {
        if (val !== undefined) {
            this.paused = val;
            this._checkQueue();
        }
        return this.paused;
    }

    private _checkQueue(): void {
        while (
            !this.paused &&
            this.queue.length &&
            this.current.length < this.opts.maxRequests
        ) {
            this._processOne(this.queue.shift()!);
        }
    }

    private _processOne(url: string): void {
        const img = (this.pool.shift() || new Image()) as PoolImage;
        this.current.push(img);
        const handler = (e: Event): void => {
            img.removeEventListener('load',  handler);
            img.removeEventListener('error', handler);
            img.removeEventListener('abort', handler);
            img._loaderHandler = null;
            img.onload = null;

            const idx = this.current.indexOf(img);
            if (idx !== -1) this.current.splice(idx, 1);

            if (e.type === "load") {
                this.loaded++;
                this.errorEma *= 0.9;
            } else {
                this.errors++;
                this.errorEma++;
                if (this.errorEma >= 20 && this.errorEma < 21)
                    this.paused = true;
            }
            this._fireChanged(e.type, img);
            this._checkQueue();
            this.pool.push(img);
        };

        img._loaderHandler = handler;
        img.addEventListener('load',  handler);
        img.addEventListener('error', handler);
        img.addEventListener('abort', handler);
        img.src = url;
    }

    private _fireChanged(type: string, img?: PoolImage): void {
        this.opts.onChanged(type, img);
    }
}

export default ImageLoader;
