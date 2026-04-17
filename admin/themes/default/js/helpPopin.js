/* Shared help-popin handler: fetch help content and display in a <dialog>.
   Works for both .help-popin (page-level) and .help-popin-search (search tips). */
document.addEventListener('DOMContentLoaded', function () {
    var dlg = null;
    document.querySelectorAll('.help-popin, .help-popin-search').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            if (!dlg) {
                dlg = document.createElement('dialog');
                dlg.className = 'help-popin-dialog';
                dlg.innerHTML = '<button class="close-dialog" style="float:right">\u00d7</button>' +
                    '<div class="help-content" style="clear:both"></div>';
                dlg.querySelector('.close-dialog').addEventListener('click', function () { dlg.close(); });
                document.body.appendChild(dlg);
            }
            fetch(a.href)
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    dlg.querySelector('.help-content').innerHTML = html;
                    dlg.showModal();
                });
        });
    });
});
