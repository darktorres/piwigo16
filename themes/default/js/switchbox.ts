(function () {
    function sbFunc(link: string, box: string): void {
        document.querySelectorAll<HTMLElement>(link).forEach(linkEl => {
            linkEl.addEventListener('click', (e) => {
                e.preventDefault();
                const elt = document.querySelector<HTMLElement>(box);
                if (!elt) return;
                const trigger = e.currentTarget as HTMLElement;
                const parentRect = (trigger.offsetParent as HTMLElement | null)?.getBoundingClientRect() ?? { left: 0, top: 0 };
                const triggerRect = trigger.getBoundingClientRect();
                const left = triggerRect.left - parentRect.left;
                const top = triggerRect.top - parentRect.top;
                elt.style.left = Math.min(left, window.innerWidth - elt.offsetWidth - 5) + 'px';
                elt.style.top = (top + trigger.offsetHeight) + 'px';
                elt.style.display = elt.style.display === 'none' ? '' : 'none';
            });
        });

        const boxEl = document.querySelector<HTMLElement>(box);
        if (boxEl) {
            boxEl.addEventListener('mouseleave', () => { boxEl.style.display = 'none'; });
            boxEl.addEventListener('click', () => { boxEl.style.display = 'none'; });
        }
    }

    if (typeof SwitchBox !== 'undefined' && Array.isArray(SwitchBox)) {
        for (let i = 0; i < (SwitchBox as string[]).length; i += 2) {
            sbFunc((SwitchBox as string[])[i], (SwitchBox as string[])[i + 1]);
        }
    }

    SwitchBox = { push: sbFunc };
})();
