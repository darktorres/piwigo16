(function () {
    var sbFunc = function (link, box) {
        var linkEl = typeof link === 'string' ? document.querySelector(link) : link;
        var boxEl = typeof box === 'string' ? document.querySelector(box) : box;
        if (!linkEl || !boxEl) return;

        linkEl.addEventListener('click', function (e) {
            var style = window.getComputedStyle(boxEl);
            var boxW = boxEl.offsetWidth
                + parseFloat(style.marginLeft)
                + parseFloat(style.marginRight);
            var linkStyle = window.getComputedStyle(linkEl);
            var linkH = linkEl.offsetHeight
                + parseFloat(linkStyle.marginTop)
                + parseFloat(linkStyle.marginBottom);

            boxEl.style.left = Math.min(linkEl.offsetLeft, window.innerWidth - boxW - 5) + 'px';
            boxEl.style.top = (linkEl.offsetTop + linkH) + 'px';

            var isVisible = window.getComputedStyle(boxEl).display !== 'none';
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
    };

    if (window.SwitchBox) {
        for (var i = 0; i < SwitchBox.length; i += 2)
            sbFunc(SwitchBox[i], SwitchBox[i + 1]);
    }

    window.SwitchBox = {
        push: sbFunc,
    };
})();
