export {};

const _docReady = function (fn: () => void): void {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
};

_docReady(function () {
    let dlg: HTMLDialogElement | null = null;
    document.querySelectorAll<HTMLAnchorElement>('.help-popin, .help-popin-search').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            if (!dlg) {
                dlg = document.createElement('dialog');
                dlg.className = 'help-popin-dialog';
                dlg.innerHTML = '<button class="close-dialog" style="float:right">\u00d7</button>' +
                    '<div class="help-content" style="clear:both"></div>';
                dlg.querySelector('.close-dialog')!.addEventListener('click', function () { dlg!.close(); });
                document.body.appendChild(dlg);
            }
            void fetch(a.href)
                .then(r => r.text())
                .then(function (html) {
                    dlg!.querySelector<HTMLElement>('.help-content')!.innerHTML = html;
                    dlg!.showModal();
                });
        });
    });
});
