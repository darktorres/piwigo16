/* Menubar: expand active section, collapse others on click */
(function () {
    var menubar = document.getElementById('menubar');
    if (!menubar) return;
    var dds = menubar.querySelectorAll('dd');
    var active = parseInt(menubar.dataset.activeMenu || '0', 10);
    dds.forEach(function (dd, i) {
        dd.style.display = (i === active) ? '' : 'none';
    });
    menubar.addEventListener('click', function (e) {
        var dt = e.target.closest('dt');
        if (!dt) return;
        var dd = dt.nextElementSibling;
        if (!dd || dd.tagName !== 'DD') return;
        dds.forEach(function (el) { el.style.display = 'none'; });
        dd.style.display = '';
    });
})();

/* Show bullet points when multiple items exist in info/error/warning boxes;
   mirror the active page title from h2 into h1 */
document.addEventListener('DOMContentLoaded', function () {
    ["infos", "errors", "warnings", "messages"].forEach(function (boxType) {
        var listItems = document.querySelectorAll("." + boxType + " ul li");
        if (listItems.length > 1) {
            listItems.forEach(function (el) { el.style.listStyleType = "square"; });
            document.querySelectorAll("." + boxType + " .eiw-icon").forEach(function (el) {
                el.style.marginRight = "20px";
            });
        }
    });

    var h2 = document.querySelector('h2');
    var h1 = document.querySelector('h1');
    if (h2 && h1) h1.innerHTML = h2.innerHTML;
});
