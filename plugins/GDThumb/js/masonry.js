/* Pinterest-style masonry layout for GDThumb
 *
 * Columns have a fixed pixel width. Column count = floor((containerWidth + gap) / (colWidth + gap)).
 * Each item is absolutely positioned at the top of the shortest column.
 * Existing item positions are never touched — new items only append to column bottoms.
 * Upward RVTS scroll is disabled (preventDefault, items dropped).
 */
var GDMasonry = (function () {
    var _colWidth = 300;
    var _gap = 4;
    var _colHeights = [];
    var _ncols = 0;
    var _c = null; // #thumbnails container (DOM element)

    function _colCount() {
        return Math.max(1, Math.floor((_c.clientWidth + _gap) / (_colWidth + _gap)));
    }

    function _shortest() {
        var min = 0;
        for (var i = 1; i < _colHeights.length; i++) {
            if (_colHeights[i] < _colHeights[min]) min = i;
        }
        return min;
    }

    // Styles are applied before the element is appended so there is no
    // intermediate paint with the item at position 0,0.
    function _place(li) {
        var img = li.querySelector('img.thumbnail');
        var iw = img ? (parseInt(img.getAttribute('width'))  || _colWidth) : _colWidth;
        var ih = img ? (parseInt(img.getAttribute('height')) || _colWidth) : _colWidth;
        var itemH = Math.round(ih * _colWidth / iw);
        var col   = _shortest();

        li.style.position = 'absolute';
        li.style.width    = _colWidth + 'px';
        li.style.height   = itemH + 'px';
        li.style.left     = (col * (_colWidth + _gap)) + 'px';
        li.style.top      = _colHeights[col] + 'px';

        _colHeights[col] += itemH + _gap;
    }

    function _setHeight() {
        if (!_$c || !_$c.length) return;
        var maxH = Math.max.apply(null, _colHeights);
        _c.style.height = Math.max(0, maxH - _gap) + 'px';
    }

    // Full re-layout of every child — used on init and on resize when
    // column count changes.
    function layout() {
        if (!_$c || !_$c.length) return;
        _ncols = _colCount();
        _colHeights = new Array(_ncols).fill(0);
        Array.from(_c.querySelectorAll(':scope > li')).forEach(function(li) { _place(li); });
        _setHeight();
    }

    // Append an array of new <li> elements without disturbing existing ones.
    // Falls back to full re-layout only when the column count has changed
    // (e.g. the window was resized between two infinite-scroll batches).
    function addItems(items) {
        if (_colCount() !== _ncols) {
            items.forEach(function(li) { _c.appendChild(li); });
            layout();
            return;
        }
        items.forEach(function(li) {
            _place(li);     // position set before append — no flash
            _c.appendChild(li);
        });
        _setHeight();
    }

    function init(colWidth, gap) {
        _colWidth = colWidth || 300;
        _gap      = gap      || 4;
        _c = document.querySelector('ul#thumbnails');
        if (!_c) return;

        layout();

        // Reflow on resize, debounced.
        var _t;
        window.addEventListener('resize', function () {
            clearTimeout(_t);
            _t = setTimeout(layout, 150);
        });

        // Intercept RVTS infinite-scroll inserts.
        // preventDefault() stops RVTS from doing its own .append()/.prepend().
        window.addEventListener('RVTS_add', function (event) {
            event.preventDefault();

            if (!event.detail.addToEnd) return; // upward scroll disabled — items dropped

            var tmp = document.createElement('div');
            tmp.innerHTML = event.detail.htm;
            var items = Array.from(tmp.querySelectorAll(':scope > li'));
            if (!items.length) items = Array.from(tmp.querySelectorAll('li'));
            if (items.length) addItems(items);
        });
    }

    // Position any <li> children that were appended directly to the container
    // without going through addItems() — used by RVTS_CATS which bypasses RVTS_add.
    function positionNew() {
        if (!_c) return;
        var items = Array.from(_c.querySelectorAll(':scope > li')).filter(function(li) {
            return !li.style.position;
        });
        if (!items.length) return;
        if (_colCount() !== _ncols) {
            layout();
            return;
        }
        items.forEach(function(li) { _place(li); });
        _setHeight();
    }

    return { init: init, layout: layout, addItems: addItems, positionNew: positionNew };
})();
