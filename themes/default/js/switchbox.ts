export {};

function sbFunc(link: string | Element, box: string | Element): void {
    const linkEl = (typeof link === 'string' ? document.querySelector(link) : link) as HTMLElement | null;
    const boxEl = (typeof box === 'string' ? document.querySelector(box) : box) as HTMLElement | null;
    if (!linkEl || !boxEl) return;

    linkEl.addEventListener('click', function (e) {
        const style = window.getComputedStyle(boxEl);
        const boxW = boxEl.offsetWidth
            + parseFloat(style.marginLeft)
            + parseFloat(style.marginRight);
        const linkStyle = window.getComputedStyle(linkEl);
        const linkH = linkEl.offsetHeight
            + parseFloat(linkStyle.marginTop)
            + parseFloat(linkStyle.marginBottom);

        boxEl.style.left = Math.min(linkEl.offsetLeft, window.innerWidth - boxW - 5) + 'px';
        boxEl.style.top = (linkEl.offsetTop + linkH) + 'px';

        const isVisible = window.getComputedStyle(boxEl).display !== 'none';
        boxEl.style.display = isVisible ? 'none' : 'block';
        e.preventDefault();
        return false;
    });

    boxEl.addEventListener('mouseleave', function () {
        boxEl.style.display = 'none';
    });
    boxEl.addEventListener('click', function () {
        boxEl.style.display = 'none';
    });
}

if (document._switchBoxQueue && Array.isArray(document._switchBoxQueue)) {
    for (let i = 0; i < document._switchBoxQueue.length; i += 2)
        sbFunc(document._switchBoxQueue[i]!, document._switchBoxQueue[i + 1]!);
}

window.SwitchBox = {
    push: sbFunc,
};
