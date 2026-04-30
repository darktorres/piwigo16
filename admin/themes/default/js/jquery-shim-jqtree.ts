// Minimal jQuery shim satisfying jqTree 1.8's four jQuery touch points:
//   1. jQuery(el)                  — element wrapping
//   2. $.fn plugin registration    — $.fn.tree = pluginFn
//   3. jQuery.Event(name, data)    — custom event creation
//   4. this.element.trigger(event) — event dispatch
//   5. this.element.data(key, val) — widget instance storage
//   6. jQuery.ajax(opts)           — URL loading (albums.ts never uses it)
//
// Usage: import before jqtree so window.jQuery exists when jqtree runs.

type JqObj = {
    _el: Element | null;
    [n: number]: Element | null;
    length: number;
    [key: string]: any;
};

const jqFn: Record<string, (...args: any[]) => any> = {
    trigger(this: JqObj, event: Event | string): JqObj {
        const e = typeof event === 'string'
            ? new CustomEvent(event, { bubbles: true, cancelable: true })
            : event;
        this._el?.dispatchEvent(e);
        return this;
    },
    on(this: JqObj, names: string, fn: EventListenerOrEventListenerObject): JqObj {
        names.split(' ').filter(Boolean).forEach(n => this._el?.addEventListener(n, fn));
        return this;
    },
    off(this: JqObj, names: string, fn?: EventListenerOrEventListenerObject): JqObj {
        if (fn) names.split(' ').filter(Boolean).forEach(n => this._el?.removeEventListener(n, fn));
        return this;
    },
    data(this: JqObj, key: string, value?: unknown): any {
        (this._el as any).__jqData__ ??= {};
        if (value !== undefined) { (this._el as any).__jqData__[key] = value; return this; }
        return (this._el as any).__jqData__?.[key];
    },
    find(this: JqObj, selector: string): JqObj {
        return jq(this._el?.querySelector(selector) ?? null);
    },
    addClass(this: JqObj, cls: string): JqObj {
        cls.split(' ').filter(Boolean).forEach(c => this._el?.classList.add(c));
        return this;
    },
    attr(this: JqObj, name: string, value?: string): any {
        if (value !== undefined) { this._el?.setAttribute(name, value); return this; }
        return this._el?.getAttribute(name);
    },
    html(this: JqObj, content?: string): any {
        if (content !== undefined) { if (this._el) (this._el as HTMLElement).innerHTML = content; return this; }
        return (this._el as HTMLElement)?.innerHTML ?? '';
    },
};

function jq(selector: string | Element | null | EventTarget): JqObj {
    let el: Element | null;
    if (typeof selector === 'string') {
        if (selector.trim().startsWith('<')) {
            const d = document.createElement('div');
            d.innerHTML = selector;
            el = d.firstElementChild;
        } else {
            el = document.querySelector(selector);
        }
    } else {
        el = selector as Element | null;
    }
    const obj: JqObj = Object.create(jqFn);
    obj._el = el;
    obj[0] = el;
    obj.length = el ? 1 : 0;
    return obj;
}

(jq as any).fn = jqFn;

(jq as any).Event = (name: string, data?: Record<string, unknown>): Event => {
    const event = new CustomEvent(name, { bubbles: true, cancelable: true });
    if (data) Object.assign(event, data);
    return event;
};

(jq as any).ajax = (opts: Record<string, any>): void => {
    const url = opts.url ?? '';
    const method = (opts.method ?? opts.type ?? 'GET').toUpperCase();
    fetch(url, { method })
        .then(r => r.json())
        .then((d: unknown) => opts.success?.(d))
        .catch((e: unknown) => opts.error?.(e));
};

// Stub for widget registration (jqTree's simple.widget uses this pattern)
(jq as any).Widget = function() {};

(window as any).jQuery = jq;
(window as any).$ = jq;

export {};
