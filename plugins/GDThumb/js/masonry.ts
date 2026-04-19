/* Pinterest-style masonry layout for GDThumb */

let _colWidth = 300;
let _gap = 4;
let _colHeights: number[] = [];
let _ncols = 0;
let _c: HTMLElement | null = null;

function _colCount(): number {
    return Math.max(1, Math.floor((_c!.clientWidth + _gap) / (_colWidth + _gap)));
}

function _shortest(): number {
    let min = 0;
    for (let i = 1; i < _colHeights.length; i++) {
        if ((_colHeights[i] ?? 0) < (_colHeights[min] ?? 0)) min = i;
    }
    return min;
}

function _place(li: HTMLElement): void {
    const img = li.querySelector<HTMLImageElement>('img.thumbnail');
    const iw = img ? (parseInt(img.getAttribute('width') ?? '') || _colWidth) : _colWidth;
    const ih = img ? (parseInt(img.getAttribute('height') ?? '') || _colWidth) : _colWidth;
    const itemH = Math.round(ih * _colWidth / iw);
    const col   = _shortest();

    li.style.position = 'absolute';
    li.style.width    = _colWidth + 'px';
    li.style.height   = itemH + 'px';
    li.style.left     = (col * (_colWidth + _gap)) + 'px';
    li.style.top      = (_colHeights[col] ?? 0) + 'px';

    _colHeights[col]! += itemH + _gap;
}

function _setHeight(): void {
    if (!_c) return;
    const maxH = Math.max(..._colHeights);
    _c.style.height = Math.max(0, maxH - _gap) + 'px';
}

function layout(): void {
    if (!_c) return;
    _ncols = _colCount();
    _colHeights = new Array(_ncols).fill(0) as number[];
    Array.from(_c.querySelectorAll<HTMLElement>(':scope > li')).forEach(function (li) { _place(li); });
    _setHeight();
}

function addItems(items: HTMLElement[]): void {
    if (_colCount() !== _ncols) {
        items.forEach(function (li) { _c?.appendChild(li); });
        layout();
        return;
    }
    items.forEach(function (li) {
        _place(li);
        _c?.appendChild(li);
    });
    _setHeight();
}

function init(colWidth: number, gap: number): void {
    _colWidth = colWidth || 300;
    _gap      = gap      || 4;
    _c = document.querySelector<HTMLElement>('ul#thumbnails');
    if (!_c) return;

    layout();

    let _t: ReturnType<typeof setTimeout>;
    window.addEventListener('resize', function () {
        clearTimeout(_t);
        _t = setTimeout(layout, 150);
    });

    window.addEventListener('RVTS_add', function (event) {
        const customEvt = event as CustomEvent<{ addToEnd: boolean; htm: string }>;
        customEvt.preventDefault();

        if (!customEvt.detail.addToEnd) return;

        const tmp = document.createElement('div');
        tmp.innerHTML = customEvt.detail.htm;
        let items = Array.from(tmp.querySelectorAll<HTMLElement>(':scope > li'));
        if (!items.length) items = Array.from(tmp.querySelectorAll<HTMLElement>('li'));
        if (items.length) addItems(items);
    });
}

function positionNew(): void {
    if (!_c) return;
    const items = Array.from(_c.querySelectorAll<HTMLElement>(':scope > li')).filter(function (li) {
        return !li.style.position;
    });
    if (!items.length) return;
    if (_colCount() !== _ncols) {
        layout();
        return;
    }
    items.forEach(function (li) { _place(li); });
    _setHeight();
}

export const GDMasonry = { init, layout, addItems, positionNew };
