function hoverIntent(elements, cfg) {
    const sensitivity = cfg.sensitivity || 7;
    const interval    = cfg.interval    || 100;
    const timeout     = cfg.timeout     || 0;
    let cX = 0, cY = 0;

    elements.forEach(function(el) {
        let pX, pY, timer, outTimer, active = false;

        function track(e) { cX = e.pageX; cY = e.pageY; }

        function compare(ev) {
            if (Math.abs(pX - cX) + Math.abs(pY - cY) < sensitivity) {
                el.removeEventListener('mousemove', track);
                active = true;
                cfg.over.call(el, ev);
            } else {
                pX = cX; pY = cY;
                timer = setTimeout(function() { compare(ev); }, interval);
            }
        }

        el.addEventListener('mouseenter', function(e) {
            clearTimeout(outTimer);
            pX = e.pageX; pY = e.pageY;
            el.addEventListener('mousemove', track);
            timer = setTimeout(function() { compare(e); }, interval);
        });

        el.addEventListener('mouseleave', function(e) {
            clearTimeout(timer);
            el.removeEventListener('mousemove', track);
            if (active) {
                active = false;
                if (timeout > 0) {
                    outTimer = setTimeout(function() { if (cfg.out) cfg.out.call(el, e); }, timeout);
                } else {
                    if (cfg.out) cfg.out.call(el, e);
                }
            }
        });
    });
}

export function initThumbPop() {
    const thumbs = document.querySelectorAll('#thumbnails li>a>img');
    if (!thumbs.length) return;

    hoverIntent(Array.from(thumbs), {
        interval: 150,
        sensitivity: 3,
        over: function () {
            const srcImg = this;
            const pop    = document.getElementById('pop');
            if (!pop) return;

            const rawData = srcImg.getAttribute('data-pop') || srcImg.dataset && srcImg.dataset.pop;
            if (!rawData) return;
            let data;
            try { data = JSON.parse(rawData); } catch(_e) { return; }

            data = Object.assign({
                iw: srcImg.offsetWidth,
                ih: srcImg.offsetHeight,
                image: new Image()
            }, data);
            data.image.src = data.url;
            if (window.devicePixelRatio) {
                data.w = Math.floor(data.w / window.devicePixelRatio);
                data.h = Math.floor(data.h / window.devicePixelRatio);
            }

            const srcRect  = srcImg.getBoundingClientRect();
            const srcLeft  = srcRect.left + window.scrollX;
            const srcTop   = srcRect.top  + window.scrollY;

            let finalLeft = srcLeft - (data.w - data.iw) / 2;
            let remaining = window.scrollX + window.innerWidth - (finalLeft + data.w) - 1;
            if (remaining < 0) finalLeft += remaining;
            finalLeft = Math.max(finalLeft, window.scrollX);

            let finalTop = srcTop - (data.h - data.ih) / 2;
            remaining = window.scrollY + window.innerHeight - (finalTop + data.h) - 60;
            if (remaining < 0) finalTop += remaining;
            finalTop = Math.max(finalTop, window.scrollY);

            const aParent = srcImg.closest('a');
            const aHref   = aParent ? aParent.getAttribute('href') : '#';
            const liEl    = srcImg.closest('li');
            const descEl  = liEl ? liEl.querySelector('.popDesc') : null;
            const descHtml = descEl ? descEl.innerHTML : '';

            pop.innerHTML =
                '<div><a href="' + aHref + '"><img src="' + srcImg.getAttribute('src') +
                '" style="width:100%;height:' + data.ih + 'px"></a></div>' +
                '<div style="overflow:hidden;height:5em;background-color:#000">' + descHtml + '</div>';

            pop.style.transition = '';
            pop.style.left    = srcLeft + 'px';
            pop.style.top     = srcTop  + 'px';
            pop.style.width   = data.iw + 'px';
            pop.style.display = '';

            const popImg = pop.querySelector('img');

            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    pop.style.transition = 'width 100ms, left 100ms, top 100ms';
                    pop.style.left  = finalLeft + 'px';
                    pop.style.top   = finalTop  + 'px';
                    pop.style.width = data.w    + 'px';
                    if (popImg) {
                        popImg.style.transition = 'height 100ms';
                        popImg.style.height = data.h + 'px';
                        popImg.src = data.url;
                    }
                    pop.addEventListener('transitionend', function onEnd() {
                        pop.removeEventListener('transitionend', onEnd);
                        pop.style.transition = '';
                        if (popImg) popImg.style.transition = '';
                        pop.addEventListener('mouseleave', function() {
                            pop.style.display = 'none';
                        }, {once: true});
                    }, {once: true});
                });
            });
        },
        out: function() {}
    });
}

document.addEventListener('DOMContentLoaded', initThumbPop);
