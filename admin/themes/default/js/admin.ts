import tippy from 'tippy.js';

const _docReady = function (fn: () => void): void {
    document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn);
};

const menubar = document.getElementById('menubar');
if (menubar) {
    const dds = menubar.querySelectorAll<HTMLElement>('dd');
    const active = parseInt(menubar.dataset.activeMenu ?? '0', 10);
    dds.forEach(function (dd, i) {
        dd.style.display = (i === active) ? '' : 'none';
    });
    menubar.addEventListener('click', function (e) {
        const dt = (e.target as HTMLElement).closest('dt');
        if (!dt) return;
        const dd = dt.nextElementSibling;
        if (!dd || dd.tagName !== 'DD') return;
        dds.forEach(function (el) { el.style.display = 'none'; });
        (dd as HTMLElement).style.display = '';
    });
}

_docReady(function () {
    ['infos', 'errors', 'warnings', 'messages'].forEach(function (boxType) {
        const listItems = document.querySelectorAll('.' + boxType + ' ul li');
        if (listItems.length > 1) {
            listItems.forEach(function (el) { (el as HTMLElement).style.listStyleType = 'square'; });
            document.querySelectorAll<HTMLElement>('.' + boxType + ' .eiw-icon').forEach(function (el) {
                el.style.marginRight = '20px';
            });
        }
    });

    const h2 = document.querySelector('h2');
    const h1 = document.querySelector('h1');
    if (h2 && h1) h1.innerHTML = h2.innerHTML;

    tippy('.tiptip', { delay: 0, placement: 'top' });

    document.querySelectorAll<HTMLAnchorElement>('a.externalLink').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            window.open(el.getAttribute('href') ?? '');
        });
    });
});
