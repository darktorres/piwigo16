export function initModusAsync() {
    const albumActionsSwitcher = document.getElementById('albumActionsSwitcher');
    if (albumActionsSwitcher) {
        albumActionsSwitcher.addEventListener('click', function() {
            const box = this.parentElement.querySelector('.categoryActions');
            if (!box) return;
            if (box.offsetParent !== null) {
                box.style.display = '';
            } else {
                document.querySelectorAll('#menubar,.switchBox').forEach(function(el) {
                    el.style.display = '';
                });
                const style = window.getComputedStyle(box);
                const boxW = box.offsetWidth
                    + parseFloat(style.marginLeft)
                    + parseFloat(style.marginRight);
                box.style.left = Math.min(albumActionsSwitcher.offsetLeft, window.innerWidth - boxW - 5) + 'px';
                box.style.top = (albumActionsSwitcher.offsetTop + albumActionsSwitcher.offsetHeight) + 'px';
                box.style.display = 'block';
            }
        });
    }

    if (!("ontouchstart" in document)) {
        document.querySelectorAll('.categoryActions').forEach(function(el) {
            el.addEventListener('mouseleave', function() {
                const switcher = document.getElementById('albumActionsSwitcher');
                if (switcher && switcher.offsetParent !== null) {
                    this.style.display = '';
                }
            });
        });
    }

    const imageActionsSwitch = document.getElementById('imageActionsSwitch');
    if (imageActionsSwitch) {
        imageActionsSwitch.addEventListener('click', function() {
            const box = document.querySelector('.actionButtons');
            if (!box) return;
            if (box.offsetParent !== null) {
                box.style.display = '';
            } else {
                document.querySelectorAll('#menubar,.switchBox').forEach(function(el) {
                    el.style.display = '';
                });
                const style = window.getComputedStyle(box);
                const boxW = box.offsetWidth
                    + parseFloat(style.marginLeft)
                    + parseFloat(style.marginRight);
                box.style.left = Math.min(imageActionsSwitch.offsetLeft, window.innerWidth - boxW - 5) + 'px';
                box.style.top = (imageActionsSwitch.offsetTop + imageActionsSwitch.offsetHeight) + 'px';
                box.style.display = 'block';
            }
        });
    }

    if (!("ontouchstart" in document)) {
        document.querySelectorAll('.actionButtons').forEach(function(el) {
            el.addEventListener('mouseleave', function() {
                const switcher = document.getElementById('imageActionsSwitch');
                if (switcher && switcher.offsetParent !== null) {
                    this.style.display = '';
                }
            });
        });
    }
}

document.addEventListener('DOMContentLoaded', initModusAsync);
