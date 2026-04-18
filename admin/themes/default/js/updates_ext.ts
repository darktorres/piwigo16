import { initModule } from './moduleInit.js';
import { pwgConfirm } from './pwgConfirm.js';

interface UpdatesExtConfig {
    pwgToken?: string;
    extType?: string;
    confirmMsg?: string;
    errorHead?: string;
    successHead?: string;
    errorMsg?: string;
    restoreMsg?: string;
    titleConfirmUpdateAll?: string;
    strConfirm?: string;
    strCancel?: string;
}

interface QueueItem {
    url: string;
    data: Record<string, string>;
    success?: (data: Record<string, unknown>) => void;
    error?: () => void;
}

interface ToastOptions { theme?: string; header?: string; life?: number; sticky?: boolean }

export function init(cfg: UpdatesExtConfig): void {
    const pwg_token = cfg.pwgToken ?? '';
    const extType_local = cfg.extType ?? '';
    const confirmMsg_local = cfg.confirmMsg ?? 'Are you sure?';
    const errorHead_local = cfg.errorHead ?? 'ERROR';
    const successHead_local = cfg.successHead ?? 'Update Complete';
    const errorMsg_local = cfg.errorMsg ?? 'an error happened';
    const restoreMsg_local = cfg.restoreMsg ?? 'Reset ignored updates';

    let todo = 0;
    let _qRunning = false;
    const _qPending: QueueItem[] = [];

    const queuedManager = {
        add(config: QueueItem) {
            _qPending.push(config);
            if (!_qRunning) _qRun();
        },
    };

    function _qRun(): void {
        if (_qPending.length === 0) { _qRunning = false; return; }
        _qRunning = true;
        const item = _qPending.shift()!;
        autoupdate_bar_toggle(1);
        const params = new URLSearchParams(item.data);
        fetch(item.url + '?' + params)
            .then(r => r.json())
            .then(function (data: Record<string, unknown>) {
                autoupdate_bar_toggle(-1);
                item.success?.(data);
            })
            .catch(function (err: unknown) {
                autoupdate_bar_toggle(-1);
                item.error?.();
                void err;
            })
            .then(_qRun);
    }

    function pwgToast(msg: string, opts: ToastOptions = {}): void {
        const d = document.createElement('div');
        d.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;padding:10px 16px;border-radius:4px;color:#fff;max-width:320px;font-size:13px;box-shadow:0 2px 8px rgba(0,0,0,.3)';
        d.style.background = opts.theme === 'success' ? '#4caf50' : '#e53935';
        if (opts.header) {
            const s = document.createElement('strong');
            s.textContent = opts.header + ': ';
            d.appendChild(s);
        }
        d.appendChild(document.createTextNode(msg));
        if (opts.sticky) {
            const btn = document.createElement('button');
            btn.textContent = '×';
            btn.style.cssText = 'margin-left:8px;background:none;border:none;color:#fff;cursor:pointer;font-size:16px;';
            btn.onclick = () => d.remove();
            d.appendChild(btn);
        }
        document.body.appendChild(d);
        if (!opts.sticky) {
            setTimeout(function () {
                d.animate([{ opacity: '1' }, { opacity: '0' }], { duration: 400 }).onfinish = () => d.remove();
            }, opts.life ?? 3000);
        }
    }

    function updateAll(): void {
        document.querySelectorAll<HTMLElement>('.updateExtension').forEach(function (el) {
            const parentDiv = el.closest('div');
            if (parentDiv && window.getComputedStyle(parentDiv).display === 'block') el.click();
        });
    }

    function ignoreAll(): void {
        document.querySelectorAll<HTMLElement>('.ignoreExtension').forEach(function (el) {
            const parentDiv = el.closest('div');
            if (parentDiv && window.getComputedStyle(parentDiv).display === 'block') el.click();
        });
    }

    function resetIgnored(): void {
        const params = new URLSearchParams({
            method: 'pwg.extensions.ignoreUpdate', reset: 'true',
            type: extType_local, pwg_token, format: 'json',
        });
        fetch('ws.php?' + params)
            .then(r => r.json())
            .then(function (data: Record<string, unknown>) {
                if (data['stat'] === 'ok') {
                    document.querySelectorAll<HTMLElement>(".pluginBox, fieldset").forEach(el => { el.style.display = ''; });
                    document.querySelectorAll(".pluginBox").forEach(el => el.setAttribute('data-ignored', 'false'));
                    ['update_all', 'ignore_all'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.style.display = '';
                    });
                    ['up_to_date', 'reset_ignore', 'ignored'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.style.display = 'none';
                    });
                    checkFieldsets();
                }
            });
    }

    function checkFieldsets(): void {
        const types = ['plugins', 'themes', 'languages'];
        let total = 0, ignored = 0;
        for (const type of types) {
            let nbExtensions = 0;
            document.querySelectorAll("fieldset[data-type=" + type + "] .pluginBox").forEach(function (el) {
                if (el.getAttribute('data-ignored') === 'true') ignored++;
                else nbExtensions++;
            });
            total += nbExtensions;
            if (nbExtensions === 0) { const el = document.getElementById(type); if (el) el.style.display = 'none'; }
        }
        if (total === 0) {
            ['update_all', 'ignore_all'].forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; });
            const el = document.getElementById('up_to_date'); if (el) el.style.display = '';
        }
        if (ignored > 0) {
            const el = document.getElementById("reset_ignore");
            if (el) (el as HTMLInputElement).value = restoreMsg_local + ' (' + ignored + ')';
        }
    }

    function updateExtension(type: string, id: string, revision: string): void {
        queuedManager.add({
            url: 'ws.php',
            data: { method: 'pwg.extensions.update', type, id, revision, pwg_token, format: 'json' },
            success(data) {
                if (data['stat'] === 'ok') {
                    pwgToast(String(data['result']), { theme: 'success', header: successHead_local, life: 4000 });
                    document.getElementById(type + "_" + id)?.remove();
                    checkFieldsets();
                } else {
                    pwgToast(String(data['result']), { theme: 'error', header: errorHead_local, sticky: true });
                }
            },
            error() { pwgToast(errorMsg_local, { theme: 'error', header: errorHead_local, sticky: true }); },
        });
    }

    function ignoreExtension(type: string, id: string): void {
        queuedManager.add({
            url: 'ws.php',
            data: { method: 'pwg.extensions.ignoreUpdate', type, id, pwg_token, format: 'json' },
            success(data) {
                if (data['stat'] === 'ok') {
                    const extEl = document.getElementById(type + "_" + id);
                    if (extEl) { extEl.style.display = 'none'; extEl.setAttribute('data-ignored', 'true'); }
                    const resetIgnoreEl = document.getElementById("reset_ignore");
                    if (resetIgnoreEl) resetIgnoreEl.style.display = '';
                    checkFieldsets();
                }
            },
        });
    }

    function autoupdate_bar_toggle(i: number): void {
        todo += i;
        if ((i === 1 && todo === 1) || (i === -1 && todo === 0)) {
            document.querySelectorAll<HTMLElement>('.autoupdate_bar').forEach(function (el) {
                el.style.display = el.style.display === 'none' ? '' : 'none';
            });
        }
    }

    checkFieldsets();

    document.addEventListener('click', function (e) {
        const target = e.target as Element;
        const updateBtn = target.closest<HTMLElement>('[data-action="updateExt"]');
        if (updateBtn) {
            e.preventDefault();
            updateExtension(updateBtn.dataset.type ?? '', updateBtn.dataset.id ?? '', updateBtn.dataset.revision ?? '');
            return;
        }
        const ignoreBtn = target.closest<HTMLElement>('[data-action="ignoreExt"]');
        if (ignoreBtn) { e.preventDefault(); ignoreExtension(ignoreBtn.dataset.type ?? '', ignoreBtn.dataset.id ?? ''); return; }
        if (target.closest('[data-action="ignoreAll"]')) { e.preventDefault(); ignoreAll(); return; }
        if (target.closest('[data-action="resetIgnored"]')) { e.preventDefault(); resetIgnored(); return; }
    });

    document.getElementById("update_all")?.addEventListener('click', function (e) {
        e.preventDefault();
        pwgConfirm({
            title: cfg.titleConfirmUpdateAll ?? "Are you sure you want to update all extensions?",
            buttons: {
                confirm: { text: cfg.strConfirm ?? "Yes, I am sure", btnClass: 'btn-red', action: updateAll },
                cancel: { text: cfg.strCancel ?? "No, I have changed my mind" },
            },
        });
    });

    void confirmMsg_local;
}

initModule(init as (cfg: Record<string, unknown>) => void);
