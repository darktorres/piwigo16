'use strict';

interface TourStep {
    path?: string;
    element?: string;
    placement?: 'top' | 'bottom' | 'left' | 'right';
    title?: string;
    content?: string;
    prev?: number;
    onShown?: (tour: Tour) => void;
}

interface TourOptions {
    name?: string;
    onEnd?: ((tour: Tour) => void) | null;
    orphan?: boolean;
    template?: string;
}

export class Tour {
    name: string;
    onEnd: ((tour: Tour) => void) | null;
    orphan: boolean;
    template: string;
    private _steps: TourStep[] = [];
    private _key: string;
    private _pop: HTMLElement | null = null;

    constructor(opts: TourOptions) {
        this.name = opts.name || 'default';
        this.onEnd = opts.onEnd ?? null;
        this.orphan = opts.orphan !== false;
        this.template = opts.template || '';
        this._key = 'piwigo-tour-' + this.name;
    }

    addSteps(steps: TourStep[]): this { this._steps = this._steps.concat(steps); return this; }
    addStep(step: TourStep): this { this._steps.push(step); return this; }

    init(): this {
        const idx = parseInt(sessionStorage.getItem(this._key) ?? '', 10);
        if (!isNaN(idx) && idx >= 0) { setTimeout(() => { this._show(idx); }, 100); }
        return this;
    }

    start(): this { this._show(0); return this; }

    restart(): this { sessionStorage.setItem(this._key, '0'); this._show(0); return this; }

    end(): void {
        sessionStorage.removeItem(this._key);
        this._removePop();
        if (this.onEnd) this.onEnd(this);
    }

    getCurrentStep(): number { return parseInt(sessionStorage.getItem(this._key) || '0', 10); }

    goTo(idx: number): this { this._show(idx); return this; }

    private _show(idx: number): void {
        if (idx < 0 || idx >= this._steps.length) { this.end(); return; }
        const step = this._steps[idx]!;
        sessionStorage.setItem(this._key, String(idx));

        if (step.path) {
            const here = window.location.pathname + window.location.search;
            if (here.indexOf(step.path.split('?')[0]!) === -1 || (step.path.indexOf('?') !== -1 && here.indexOf(step.path.split('?')[1]!) === -1)) {
                window.location.href = step.path;
                return;
            }
        }

        this._removePop();
        const target = step.element ? document.querySelector<HTMLElement>(step.element) : null;
        if (!target && !this.orphan) { this.end(); return; }
        if (step.onShown) { try { step.onShown(this); } catch (_e) { /* ignore callback errors */ } }
        this._createPop(step, idx, target);
    }

    private _createPop(step: TourStep, idx: number, target: HTMLElement | null): void {
        const wrap = document.createElement('div');
        wrap.innerHTML = this.template || '<div class="popover"><div class="arrow"></div><h3 class="popover-title"></h3><div class="popover-content"></div><div class="popover-navigation"><div class="btn-group"><button class="btn btn-sm btn-default" data-role="prev">&laquo; Prev</button><button class="btn btn-sm btn-default" data-role="next">Next &raquo;</button></div><button class="btn btn-sm btn-default" data-role="end">End tour</button></div></div>';
        const pop = wrap.firstElementChild as HTMLElement;
        pop.id = 'piwigo-tour-pop';
        pop.style.cssText = 'position:absolute;z-index:100000;background:#fff;border:1px solid rgba(0,0,0,.2);border-radius:6px;padding:1px;max-width:320px;box-shadow:0 5px 10px rgba(0,0,0,.2)';
        const titleEl = pop.querySelector('.popover-title');
        if (titleEl) titleEl.innerHTML = step.title || '';
        const contentEl = pop.querySelector('.popover-content');
        if (contentEl) contentEl.innerHTML = step.content || '';
        pop.querySelectorAll<HTMLElement>('[data-role]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const role = btn.getAttribute('data-role');
                if (role === 'next') this._show(idx + 1);
                else if (role === 'prev') this._show(step.prev ?? idx - 1);
                else if (role === 'end') this.end();
            });
        });
        document.body.appendChild(pop);
        this._positionPop(pop, target, step.placement || 'bottom');
        this._pop = pop;
    }

    private _positionPop(pop: HTMLElement, target: HTMLElement | null, placement: string): void {
        if (!target) {
            pop.style.position = 'fixed';
            pop.style.top = '50%'; pop.style.left = '50%';
            pop.style.transform = 'translate(-50%,-50%)';
            return;
        }
        const rect = target.getBoundingClientRect();
        const sx = window.scrollX || window.pageXOffset;
        const sy = window.scrollY || window.pageYOffset;
        pop.style.position = 'absolute';
        const pw = pop.offsetWidth || 320, ph = pop.offsetHeight || 150;
        if (placement === 'top')    { pop.style.top = (rect.top+sy-ph-10)+'px'; pop.style.left = (rect.left+sx+rect.width/2-pw/2)+'px'; }
        else if (placement === 'left')  { pop.style.top = (rect.top+sy+rect.height/2-ph/2)+'px'; pop.style.left = (rect.left+sx-pw-10)+'px'; }
        else if (placement === 'right') { pop.style.top = (rect.top+sy+rect.height/2-ph/2)+'px'; pop.style.left = (rect.right+sx+10)+'px'; }
        else { pop.style.top = (rect.bottom+sy+10)+'px'; pop.style.left = (rect.left+sx+rect.width/2-pw/2)+'px'; }
        target.scrollIntoView({behavior:'smooth', block:'center'});
    }

    private _removePop(): void {
        if (this._pop) { this._pop.remove(); this._pop = null; }
        const e = document.getElementById('piwigo-tour-pop'); if (e) e.remove();
    }
}
