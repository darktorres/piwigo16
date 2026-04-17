/**
 * pwgConfirm — lightweight native <dialog> replacement for jquery-confirm.
 * Exposes window.pwgConfirm(opts) and window.pwgConfirmFollowHref(el, opts).
 */
(function () {
    var styleId = 'pwg-confirm-styles';
    if (!document.getElementById(styleId)) {
        var style = document.createElement('style');
        style.id = styleId;
        style.textContent =
            '#pwg-confirm-dialog{border:none;border-radius:4px;padding:0;' +
            'box-shadow:0 4px 24px rgba(0,0,0,.45);min-width:300px;max-width:500px;}' +
            '#pwg-confirm-dialog::backdrop{background:rgba(0,0,0,.5);}' +
            '.pwg-confirm-title{margin:0;padding:14px 18px;font-size:15px;' +
            'background:#d9534f;color:#fff;border-radius:4px 4px 0 0;}' +
            '.pwg-confirm-content{padding:14px 18px;font-size:14px;}' +
            '.pwg-confirm-buttons{padding:10px 18px 14px;display:flex;' +
            'justify-content:flex-end;gap:8px;border-top:1px solid #e5e5e5;}' +
            '.pwg-confirm-buttons button{padding:6px 14px;border:1px solid #bbb;' +
            'border-radius:3px;cursor:pointer;font-size:13px;background:#f0f0f0;color:#333;}' +
            '.pwg-confirm-buttons button.btn-red{background:#d9534f;color:#fff;border-color:#c9302c;}' +
            '.pwg-confirm-buttons button.btn-orange{background:#e67e22;color:#fff;border-color:#cf6d17;}';
        document.head.appendChild(style);
    }

    var dialog = null;

    function getDialog() {
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.id = 'pwg-confirm-dialog';
            dialog.innerHTML =
                '<h3 class="pwg-confirm-title"></h3>' +
                '<div class="pwg-confirm-content"></div>' +
                '<div class="pwg-confirm-buttons"></div>';
            document.body.appendChild(dialog);
            dialog.addEventListener('click', function (e) {
                if (e.target === dialog) dialog.close();
            });
        }
        return dialog;
    }

    window.pwgConfirm = function (opts) {
        opts = opts || {};
        var dlg = getDialog();
        dlg.querySelector('.pwg-confirm-title').innerHTML = opts.title || '';
        var contentEl = dlg.querySelector('.pwg-confirm-content');
        contentEl.innerHTML = opts.content || '';
        contentEl.style.display = opts.content ? '' : 'none';
        var buttonsEl = dlg.querySelector('.pwg-confirm-buttons');
        buttonsEl.innerHTML = '';
        var buttons = opts.buttons || {};
        Object.keys(buttons).forEach(function (key) {
            var btn = buttons[key];
            var el = document.createElement('button');
            el.type = 'button';
            el.textContent = btn.text || key;
            if (btn.btnClass) el.className = btn.btnClass;
            el.addEventListener('click', function () {
                dlg.close();
                if (btn.action) btn.action.call(el);
            });
            buttonsEl.appendChild(el);
        });
        dlg.showModal();
    };

    window.pwgConfirmFollowHref = function (el, opts) {
        opts = opts || {};
        var href = el.getAttribute('href');
        var title = opts.alert_title || '';
        var confirmText = opts.alert_confirm || 'OK';
        var cancelText = opts.alert_cancel || 'Cancel';
        var content = opts.alert_content || '';
        el.addEventListener('click', function (e) {
            e.preventDefault();
            pwgConfirm({
                title: title,
                content: content,
                buttons: {
                    confirm: {
                        text: confirmText,
                        btnClass: 'btn-red',
                        action: function () { window.location.href = href; }
                    },
                    cancel: { text: cancelText }
                }
            });
        });
    };
})();
