function sbFunc(link, box) {
    const linkEl = typeof link === 'string' ? document.querySelector(link) : link;
    const boxEl = typeof box === 'string' ? document.querySelector(box) : box;
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

if (window.SwitchBox && Array.isArray(window.SwitchBox)) {
    for (let i = 0; i < window.SwitchBox.length; i += 2)
        sbFunc(window.SwitchBox[i], window.SwitchBox[i + 1]);
}

window.SwitchBox = {
    push: sbFunc,
};
