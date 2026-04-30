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
    get(this: JqObj, index?: number): Element | Element[] | undefined {
        const els: Element[] = this._el ? [this._el] : [];
        return index === undefined ? els : els[index];
    },
    empty(this: JqObj): JqObj {
        if (this._el) (this._el as HTMLElement).innerHTML = '';
        return this;
    },
    append(this: JqObj, content: JqObj | Element | string): JqObj {
        if (!this._el) return this;
        if (typeof content === 'string') { (this._el as HTMLElement).insertAdjacentHTML('beforeend', content); }
        else if (content instanceof Element) { this._el.appendChild(content); }
        else if ((content as JqObj)._el) { this._el.appendChild((content as JqObj)._el!); }
        return this;
    },
    before(this: JqObj, content: JqObj | Element | string): JqObj {
        if (!this._el?.parentNode) return this;
        if (typeof content === 'string') { this._el.insertAdjacentHTML('beforebegin', content); }
        else { const el = content instanceof Element ? content : (content as JqObj)._el; if (el) this._el.parentNode.insertBefore(el, this._el); }
        return this;
    },
    after(this: JqObj, content: JqObj | Element | string): JqObj {
        if (!this._el?.parentNode) return this;
        if (typeof content === 'string') { this._el.insertAdjacentHTML('afterend', content); }
        else { const el = content instanceof Element ? content : (content as JqObj)._el; if (el) this._el.parentNode.insertBefore(el, this._el.nextSibling); }
        return this;
    },
    remove(this: JqObj): JqObj {
        this._el?.parentNode?.removeChild(this._el);
        return this;
    },
    hide(this: JqObj): JqObj {
        if (this._el) (this._el as HTMLElement).style.display = 'none';
        return this;
    },
    show(this: JqObj): JqObj {
        if (this._el) (this._el as HTMLElement).style.display = '';
        return this;
    },
    slideUp(this: JqObj, _duration?: number | string, callback?: () => void): JqObj {
        if (this._el) (this._el as HTMLElement).style.display = 'none';
        if (callback) callback();
        return this;
    },
    slideDown(this: JqObj, _duration?: number | string, callback?: () => void): JqObj {
        if (this._el) (this._el as HTMLElement).style.display = '';
        if (callback) callback();
        return this;
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

// jqTree uses jQuery.removeData(el, key) to clean up
(jq as any).removeData = (el: Element | null, key: string): void => {
    if (el) delete (el as any).__jqData__?.[key];
};

// jqTree uses jQuery.data(el, key, val) as a static data store
(jq as any).data = (el: Element | null, key: string, value?: unknown): any => {
    if (!el) return undefined;
    (el as any).__jqData__ ??= {};
    if (value !== undefined) { (el as any).__jqData__[key] = value; }
    return (el as any).__jqData__?.[key];
};

// Stub for widget registration (jqTree's simple.widget uses this pattern)
(jq as any).Widget = function() {};

(window as any).jQuery = jq;
(window as any).$ = jq;

export {};
