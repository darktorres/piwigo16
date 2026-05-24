import '../css/pages/picture_coi.css';

document.addEventListener('DOMContentLoaded', () => {
    const imgEl = document.getElementById('jcrop') as HTMLImageElement | null;
    if (!imgEl) return;

    const lEl = document.getElementById('l') as HTMLInputElement;
    const tEl = document.getElementById('t') as HTMLInputElement;
    const rEl = document.getElementById('r') as HTMLInputElement;
    const bEl = document.getElementById('b') as HTMLInputElement;

    // Wrap image in a container for overlay positioning
    const container = document.createElement('div');
    container.style.cssText =
        'position:relative;display:inline-block;cursor:crosshair;user-select:none;';
    imgEl.parentElement?.insertBefore(container, imgEl);
    container.appendChild(imgEl);
    imgEl.style.cssText = 'max-width:500px;max-height:400px;display:block;';
    imgEl.draggable = false;

    const overlay = document.createElement('div');
    overlay.style.cssText =
        'position:absolute;border:2px solid #fff;box-shadow:0 0 0 9999px rgba(0,0,0,.5);pointer-events:none;display:none;box-sizing:border-box;';
    container.appendChild(overlay);

    let startX = 0,
        startY = 0,
        dragging = false;

    function clamp(v: number, lo: number, hi: number) {
        return Math.max(lo, Math.min(v, hi));
    }

    function relCoords(e: MouseEvent) {
        const r = imgEl!.getBoundingClientRect();
        return {
            x: clamp(e.clientX - r.left, 0, r.width),
            y: clamp(e.clientY - r.top, 0, r.height),
        };
    }

    function applyOverlay(x: number, y: number, w: number, h: number) {
        Object.assign(overlay.style, {
            left: x + 'px',
            top: y + 'px',
            width: w + 'px',
            height: h + 'px',
            display: 'block',
        });
    }

    container.addEventListener('mousedown', (e) => {
        e.preventDefault();
        const p = relCoords(e);
        startX = p.x;
        startY = p.y;
        dragging = true;
        overlay.style.display = 'none';
    });

    document.addEventListener('mousemove', (e) => {
        if (!dragging) return;
        const p = relCoords(e);
        applyOverlay(
            Math.min(startX, p.x),
            Math.min(startY, p.y),
            Math.abs(p.x - startX),
            Math.abs(p.y - startY)
        );
    });

    document.addEventListener('mouseup', (e) => {
        if (!dragging) return;
        dragging = false;
        const r = imgEl.getBoundingClientRect();
        const p = relCoords(e);
        const x = Math.min(startX, p.x),
            y = Math.min(startY, p.y);
        const w = Math.abs(p.x - startX),
            h = Math.abs(p.y - startY);
        if (w < 5 || h < 5) {
            lEl.value = tEl.value = rEl.value = bEl.value = '';
            overlay.style.display = 'none';
        } else {
            lEl.value = (x / r.width).toFixed(6);
            tEl.value = (y / r.height).toFixed(6);
            rEl.value = ((x + w) / r.width).toFixed(6);
            bEl.value = ((y + h) / r.height).toFixed(6);
        }
    });

    // Restore existing COI after image loads
    function restoreCoi() {
        if (!lEl.value || !tEl.value || !rEl.value || !bEl.value) return;
        const r = imgEl!.getBoundingClientRect();
        if (!r.width) return;
        const l = parseFloat(lEl.value),
            t = parseFloat(tEl.value);
        const ri = parseFloat(rEl.value),
            b = parseFloat(bEl.value);
        applyOverlay(l * r.width, t * r.height, (ri - l) * r.width, (b - t) * r.height);
    }

    if (imgEl.complete) restoreCoi();
    else imgEl.addEventListener('load', restoreCoi);
});

export {};
