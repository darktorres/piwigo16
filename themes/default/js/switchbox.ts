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
            elt.style.display = elt.style.display === 'none' ? '' : 'none';
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

// Process the legacy queue any caller may have populated before this module loaded.
const existing = (window as any).SwitchBox;
if (Array.isArray(existing)) {
    for (let i = 0; i < existing.length; i += 2) {
        sbFunc(existing[i], existing[i + 1]);
    }
}

// Auto-discover triggers via the data-switchbox attribute. Each trigger
// element points at its companion box: <a data-switchbox="#fooBox">.
document.querySelectorAll<HTMLElement>('[data-switchbox]').forEach((triggerEl) => {
    const box = triggerEl.dataset['switchbox'];
    if (!box) return;
    const id = triggerEl.id ? '#' + triggerEl.id : null;
    if (id) {
        sbFunc(id, box);
    } else {
        // Fallback: bind directly to this single trigger.
        sbFunc(`[data-switchbox="${box}"]`, box);
    }
});

// Keep the queue interface around for any caller still pushing.
(window as any).SwitchBox = { push: sbFunc };

export {};
