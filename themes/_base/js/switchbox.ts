function sbFunc(link: string, box: string): void {
    document.querySelectorAll<HTMLElement>(link).forEach((linkEl) => {
        linkEl.addEventListener('click', (e) => {
            e.preventDefault();
            const elt = document.querySelector<HTMLElement>(box);
            if (!elt) return;
            const trigger = e.currentTarget as HTMLElement;
            const parentRect = (
                trigger.offsetParent as HTMLElement | null
            )?.getBoundingClientRect() ?? { left: 0, top: 0 };
            const triggerRect = trigger.getBoundingClientRect();
            const left = triggerRect.left - parentRect.left;
            const top = triggerRect.top - parentRect.top;
            elt.style.left = Math.min(left, window.innerWidth - elt.offsetWidth - 5) + 'px';
            elt.style.top = top + trigger.offsetHeight + 'px';
            // Use computed display so the first click on a CSS-hidden box
            // (display: none in stylesheet, no inline style yet) opens it
            // instead of redundantly setting display:none again.
            const isHidden = getComputedStyle(elt).display === 'none';
            elt.style.display = isHidden ? 'block' : 'none';
        });
    });

    const boxEl = document.querySelector<HTMLElement>(box);
    if (boxEl) {
        boxEl.addEventListener('mouseleave', () => {
            boxEl.style.display = 'none';
        });
        boxEl.addEventListener('click', () => {
            boxEl.style.display = 'none';
        });
    }
}

// Auto-discover triggers via the data-switchbox attribute. Each trigger
// element points at its companion box: <a data-switchbox="#fooBox">.
document.querySelectorAll<HTMLElement>('[data-switchbox]').forEach((triggerEl) => {
    const box = triggerEl.dataset['switchbox'];
    if (box === undefined || box === '') return;
    const id = triggerEl.id !== '' ? '#' + triggerEl.id : null;
    if (id !== null) {
        sbFunc(id, box);
    } else {
        // Fallback: bind directly to this single trigger.
        sbFunc(`[data-switchbox="${box}"]`, box);
    }
});

export {};
