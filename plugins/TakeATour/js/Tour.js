'use strict';

export function Tour(opts) {
    this.name = opts.name || 'default';
    this.onEnd = opts.onEnd || null;
    this.orphan = opts.orphan !== false;
    this.template = opts.template || '';
    this._steps = [];
    this._key = 'piwigo-tour-' + this.name;
    this._pop = null;
}

Tour.prototype.addSteps = function(steps) { this._steps = this._steps.concat(steps); return this; };
Tour.prototype.addStep  = function(step)  { this._steps.push(step); return this; };
Tour.prototype.init     = function() {
    var idx = parseInt(sessionStorage.getItem(this._key), 10);
    if (!isNaN(idx) && idx >= 0) { var self = this; setTimeout(function() { self._show(idx); }, 100); }
    return this;
};
Tour.prototype.start    = function() { this._show(0); return this; };
Tour.prototype.restart  = function() { sessionStorage.setItem(this._key, '0'); this._show(0); return this; };
Tour.prototype.end      = function() {
    sessionStorage.removeItem(this._key);
    this._removePop();
    if (this.onEnd) this.onEnd(this);
};
Tour.prototype.getCurrentStep = function() { return parseInt(sessionStorage.getItem(this._key) || '0', 10); };
Tour.prototype.goTo = function(idx) { this._show(idx); return this; };

Tour.prototype._show = function(idx) {
    if (idx < 0 || idx >= this._steps.length) { this.end(); return; }
    var step = this._steps[idx];
    sessionStorage.setItem(this._key, String(idx));

    // Navigate to step's page if necessary
    if (step.path) {
        var here = window.location.pathname + window.location.search;
        if (here.indexOf(step.path.split('?')[0]) === -1 || (step.path.indexOf('?') !== -1 && here.indexOf(step.path.split('?')[1]) === -1)) {
            window.location.href = step.path;
            return;
        }
    }

    this._removePop();
    var target = step.element ? document.querySelector(step.element) : null;
    if (!target && !this.orphan) { this.end(); return; }
    if (step.onShown) { try { step.onShown(this); } catch(_e) {} }
    this._createPop(step, idx, target);
};

Tour.prototype._createPop = function(step, idx, target) {
    var self = this;
    var wrap = document.createElement('div');
    wrap.innerHTML = this.template || '<div class="popover"><div class="arrow"></div><h3 class="popover-title"></h3><div class="popover-content"></div><div class="popover-navigation"><div class="btn-group"><button class="btn btn-sm btn-default" data-role="prev">&laquo; Prev</button><button class="btn btn-sm btn-default" data-role="next">Next &raquo;</button></div><button class="btn btn-sm btn-default" data-role="end">End tour</button></div></div>';
    var pop = wrap.firstElementChild;
    pop.id = 'piwigo-tour-pop';
    pop.style.cssText = 'position:absolute;z-index:100000;background:#fff;border:1px solid rgba(0,0,0,.2);border-radius:6px;padding:1px;max-width:320px;box-shadow:0 5px 10px rgba(0,0,0,.2)';
    var titleEl = pop.querySelector('.popover-title');
    if (titleEl) titleEl.innerHTML = step.title || '';
    var contentEl = pop.querySelector('.popover-content');
    if (contentEl) contentEl.innerHTML = step.content || '';
    pop.querySelectorAll('[data-role]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var role = btn.getAttribute('data-role');
            if (role === 'next') self._show(idx + 1);
            else if (role === 'prev') self._show(step.prev !== undefined ? step.prev : idx - 1);
            else if (role === 'end') self.end();
        });
    });
    document.body.appendChild(pop);
    this._positionPop(pop, target, step.placement || 'bottom');
    this._pop = pop;
};

Tour.prototype._positionPop = function(pop, target, placement) {
    if (!target) {
        pop.style.position = 'fixed';
        pop.style.top = '50%'; pop.style.left = '50%';
        pop.style.transform = 'translate(-50%,-50%)';
        return;
    }
    var rect = target.getBoundingClientRect();
    var sx = window.scrollX || window.pageXOffset;
    var sy = window.scrollY || window.pageYOffset;
    pop.style.position = 'absolute';
    var pw = pop.offsetWidth || 320, ph = pop.offsetHeight || 150;
    if (placement === 'top')    { pop.style.top = (rect.top+sy-ph-10)+'px'; pop.style.left = (rect.left+sx+rect.width/2-pw/2)+'px'; }
    else if (placement === 'left')  { pop.style.top = (rect.top+sy+rect.height/2-ph/2)+'px'; pop.style.left = (rect.left+sx-pw-10)+'px'; }
    else if (placement === 'right') { pop.style.top = (rect.top+sy+rect.height/2-ph/2)+'px'; pop.style.left = (rect.right+sx+10)+'px'; }
    else { pop.style.top = (rect.bottom+sy+10)+'px'; pop.style.left = (rect.left+sx+rect.width/2-pw/2)+'px'; }
    target.scrollIntoView({behavior:'smooth', block:'center'});
};

Tour.prototype._removePop = function() {
    if (this._pop) { this._pop.remove(); this._pop = null; }
    var e = document.getElementById('piwigo-tour-pop'); if (e) e.remove();
};
