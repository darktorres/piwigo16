import { ajax, type AjaxOptions } from "./ajax";

/**
 * Port of jquery.ajaxmanager.js v3.12's own `$.manageAjax` (real source
 * read from https://github.com/aFarkas/Ajaxmanager, pinned at #3.12).
 * Every real call site in this app creates a manager with `queue: true`
 * and `cacheResponse: false` (or omits `cacheResponse`, whose own default
 * is already `false`), and never sets `queue: 'clear'`, `abortOld`,
 * `domCompleteTrigger`/`domSuccessTrigger`, or reads the `{name}AjaxStart`/
 * `AjaxComplete`/`AjaxStop`/`AjaxSuccess` custom events the original also
 * triggers (grepped for real listeners -- none). None of that response-
 * caching, event-broadcasting, or non-queued machinery is ported; only a
 * concurrency-limited FIFO queue of real ajax() calls, which is the whole
 * of what this app's own 4 real call sites actually use.
 *
 * `preventDoubleRequests` keeps its original default of `true` (the one
 * option here besides `maxRequests` any real call site overrides):
 * a second `.add()` call with the same method+url+body while the first is
 * still in flight is silently dropped, exactly like the original.
 */
export interface AjaxQueueOptions {
  maxRequests: number;
  preventDoubleRequests?: boolean;
  beforeSend?: () => void;
  complete?: () => void;
}

export class AjaxQueue {
  readonly #pending: (() => void)[] = [];
  readonly #inFlightKeys = new Set<string>();
  #inProgress = 0;
  readonly #opts: AjaxQueueOptions;

  public constructor(opts: AjaxQueueOptions) {
    this.#opts = opts;
  }

  public add<T = unknown>(requestOpts: AjaxOptions<T>): void {
    const preventDoubleRequests = this.#opts.preventDoubleRequests ?? true;
    const key =
      (requestOpts.method ?? requestOpts.type ?? "GET") +
      requestOpts.url +
      (typeof requestOpts.data === "string"
        ? requestOpts.data
        : JSON.stringify(requestOpts.data ?? {}));

    if (preventDoubleRequests && this.#inFlightKeys.has(key)) {
      return;
    }

    this.#pending.push(() => {
      this.#inFlightKeys.add(key);
      this.#inProgress++;
      this.#opts.beforeSend?.();

      const origComplete = requestOpts.complete;
      void ajax({
        ...requestOpts,
        complete: (xhr, statusText) => {
          this.#inFlightKeys.delete(key);
          this.#inProgress--;
          origComplete?.(xhr, statusText);
          this.#opts.complete?.();
          this.#dequeue();
        },
      });
    });

    this.#dequeue();
  }

  #dequeue(): void {
    while (this.#inProgress < this.#opts.maxRequests && this.#pending.length > 0) {
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- the while condition above already confirmed #pending is non-empty.
      this.#pending.shift()!();
    }
  }
}
