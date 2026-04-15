/* Pinterest-style masonry layout for GDThumb
 *
 * Columns have a fixed pixel width. Column count = floor((containerWidth + gap) / (colWidth + gap)).
 * Each item is absolutely positioned at the top of the shortest column.
 * Existing item positions are never touched — new items only append to column bottoms.
 * Upward RVTS scroll is disabled (preventDefault, items dropped).
 */
var GDMasonry = (function ($) {
    var _colWidth = 300;
    var _gap = 4;
    var _colHeights = [];
    var _ncols = 0;
    var _$c = null; // #thumbnails container

    function _colCount() {
        return Math.max(1, Math.floor((_$c.width() + _gap) / (_colWidth + _gap)));
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
    function _place($li) {
        var $img = $li.find('img.thumbnail');
        var iw = parseInt($img.attr('width'))  || _colWidth;
        var ih = parseInt($img.attr('height')) || _colWidth;
        var itemH = Math.round(ih * _colWidth / iw);
        var col   = _shortest();

        $li[0].style.position = 'absolute';
        $li[0].style.width    = _colWidth + 'px';
        $li[0].style.height   = itemH + 'px';
        $li[0].style.left     = (col * (_colWidth + _gap)) + 'px';
        $li[0].style.top      = _colHeights[col] + 'px';

        _colHeights[col] += itemH + _gap;
    }

    function _setHeight() {
        var maxH = Math.max.apply(null, _colHeights);
        _$c[0].style.height = Math.max(0, maxH - _gap) + 'px';
    }

    // Full re-layout of every child — used on init and on resize when
    // column count changes.
    function layout() {
        _ncols = _colCount();
        _colHeights = new Array(_ncols).fill(0);
        _$c.children('li').each(function () { _place($(this)); });
        _setHeight();
    }

    // Append a jQuery set of new <li> elements without disturbing existing ones.
    // Falls back to full re-layout only when the column count has changed
    // (e.g. the window was resized between two infinite-scroll batches).
    function addItems($items) {
        if (_colCount() !== _ncols) {
            _$c.append($items);
            layout();
            return;
        }
        $items.each(function () {
            var $li = $(this);
            _place($li);     // position set before append — no flash
            _$c.append($li);
        });
        _setHeight();
    }

    function init(colWidth, gap) {
        _colWidth = colWidth || 300;
        _gap      = gap      || 4;
        _$c = $('ul#thumbnails');
        if (!_$c.length) _$c = $('[data-album-grid]').first();
        if (!_$c.length) return;

        layout();

        // Reflow on resize, debounced.
        var _t;
        $(window).on('resize', function () {
            clearTimeout(_t);
            _t = setTimeout(layout, 150);
        });

        // Intercept RVTS infinite-scroll inserts.
        // preventDefault() stops RVTS from doing its own .append()/.prepend().
        $(window).on('RVTS_add', function (event, html, isDown) {
            event.preventDefault();

            if (!isDown) return; // upward scroll disabled — items dropped

            var $items = $(html).filter('li');
            if ($items.length) addItems($items);
        });
    }

    // Position any <li> children that were appended directly to the container
    // without going through addItems() — used by RVTS_CATS which bypasses RVTS_add.
    function positionNew() {
        if (!_$c || !_$c.length) return;
        var $items = _$c.children('li').filter(function () {
            return !this.style.position;
        });
        if (!$items.length) return;
        if (_colCount() !== _ncols) {
            layout();
            return;
        }
        $items.each(function () { _place($(this)); });
        _setHeight();
    }

    return { init: init, layout: layout, addItems: addItems, positionNew: positionNew };
})(jQuery);
