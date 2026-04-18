export function initMenuH() {
    const menuSwitcher = document.getElementById('menuSwitcher');
    if (menuSwitcher) {
        menuSwitcher.addEventListener('click', function() {
            const mb = document.getElementById('menubar');
            if (!mb) return;
            if (mb.offsetParent !== null) {
                mb.style.display = '';
            } else {
                document.querySelectorAll('.categoryActions,.actionButtons,.switchBox').forEach(function(el) {
                    el.style.display = '';
                });
                mb.style.top = (menuSwitcher.offsetTop + menuSwitcher.offsetHeight) + 'px';
                mb.style.display = 'block';
            }
        });
    }

    document.querySelectorAll('#menubar DT').forEach(function(dt) {
        dt.addEventListener('click', function() {
            if (window.getComputedStyle(this).display !== 'block') return;
            const siblings = Array.from(this.parentElement.children).filter(function(el) {
                return el.tagName === 'DD';
            });
            if (siblings.length) {
                const isVisible = siblings.some(function(dd) { return dd.offsetParent !== null; });
                siblings.forEach(function(dd) {
                    dd.style.display = isVisible ? '' : 'block';
                });
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initMenuH);
