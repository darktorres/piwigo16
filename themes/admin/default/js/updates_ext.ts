import { getPageData } from './page-data';
import { config } from './config';

interface UpdatesExtPageData {
    pwg_token: string;
    ext_type: string;
    str_error_head: string;
    str_error_msg: string;
    str_restore: string;
    str_confirm_update_all: string;
}

interface QueueOpts {
    beforeSend?: () => void;
    url?: string;
    data?: Record<string, string | number>;
    success?: (data: Record<string, unknown>) => void;
    error?: (err: unknown) => void;
    complete?: () => void;
}

const pageData = getPageData<UpdatesExtPageData>();

let todo = 0;
let _extQueue: Promise<void> = Promise.resolve();

const queuedManager = {
    add(opts: QueueOpts): void {
        _extQueue = _extQueue.then(() => {
            opts.beforeSend?.();
            const params = new URLSearchParams(
                Object.entries(opts.data ?? {}).reduce<Record<string, string>>((acc, [k, v]) => {
                    acc[k] = String(v);
                    return acc;
                }, {})
            );
            const baseUrl = opts.url ?? config.wsUrl;
            return fetch(baseUrl + (baseUrl.includes('?') ? '&' : '?') + params.toString())
                .then((r) => r.json())
                .then((data: Record<string, unknown>) => opts.success?.(data))
                .catch((err) => opts.error?.(err))
                .finally(() => opts.complete?.());
        });
    },
};

function pwgNotify(msg: string, theme: 'success' | 'error'): void {
    const el = document.createElement('div');
    el.style.cssText =
        'position:fixed;top:20px;right:20px;z-index:9999;padding:10px 16px;border-radius:4px;color:#fff;font-size:14px;max-width:320px;margin-bottom:5px;';
    el.style.background = theme === 'success' ? '#27ae60' : '#e74c3c';
    el.innerHTML =
        (theme === 'success' ? '<i class="icon-ok"></i> ' : '<i class="icon-attention"></i> ') +
        msg;
    document.body.appendChild(el);
    if (theme === 'success') {
        setTimeout(() => el.remove(), 4000);
    }
}

function autoupdate_bar_toggle(i: number): void {
    todo += i;
    if ((i === 1 && todo === 1) || (i === -1 && todo === 0)) {
        document.querySelectorAll<HTMLElement>('.autoupdate_bar').forEach((el) => {
            el.style.display = el.style.display === 'none' ? '' : 'none';
        });
    }
}

function checkFieldsets(): void {
    const types = ['plugins', 'themes', 'languages'];
    let total = 0;
    let ignored = 0;
    for (const t of types) {
        let nbExtensions = 0;
        document
            .querySelectorAll<HTMLElement>(`fieldset[data-type=${t}] .pluginBox`)
            .forEach((el) => {
                if (el.getAttribute('data-ignored') === 'true') {
                    ignored++;
                } else {
                    nbExtensions++;
                }
            });
        total += nbExtensions;
        if (nbExtensions === 0) {
            const el = document.getElementById(t);
            if (el) el.style.display = 'none';
        }
    }
    if (total === 0) {
        ['update_all', 'ignore_all'].forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
        const upToDate = document.getElementById('up_to_date');
        if (upToDate) upToDate.style.display = '';
    }
    if (ignored > 0) {
        const resetEl = document.getElementById('reset_ignore') as
            | HTMLInputElement
            | HTMLElement
            | null;
        if (resetEl && resetEl instanceof HTMLInputElement) {
            resetEl.value = pageData.str_restore + ' (' + ignored + ')';
        } else if (resetEl) {
            resetEl.textContent = pageData.str_restore + ' (' + ignored + ')';
        }
    }
}

function updateExtension(type: string, id: string, revision: string): void {
    queuedManager.add({
        beforeSend: () => autoupdate_bar_toggle(1),
        url: config.wsUrl,
        data: {
            method: 'pwg.extensions.update',
            type,
            id,
            revision,
            pwg_token: pageData.pwg_token,
            format: 'json',
        },
        success: (data) => {
            if (data['stat'] === 'ok') {
                pwgNotify(String(data['result']), 'success');
                document.getElementById(type + '_' + id)?.remove();
                checkFieldsets();
            } else {
                pwgNotify(String(data['result']), 'error');
            }
        },
        error: () => pwgNotify(pageData.str_error_msg, 'error'),
        complete: () => autoupdate_bar_toggle(-1),
    });
}

function ignoreExtension(type: string, id: string): void {
    queuedManager.add({
        beforeSend: () => autoupdate_bar_toggle(1),
        url: config.wsUrl,
        data: {
            method: 'pwg.extensions.ignoreUpdate',
            type,
            id,
            pwg_token: pageData.pwg_token,
            format: 'json',
        },
        success: (data) => {
            if (data['stat'] === 'ok') {
                const extEl = document.getElementById(type + '_' + id);
                if (extEl) {
                    extEl.style.display = 'none';
                    extEl.setAttribute('data-ignored', 'true');
                }
                const resetEl = document.getElementById('reset_ignore');
                if (resetEl) resetEl.style.display = '';
                checkFieldsets();
            }
        },
        complete: () => autoupdate_bar_toggle(-1),
    });
}

function updateAll(): void {
    document.querySelectorAll<HTMLElement>('.updateExtension').forEach((el) => {
        const parent = el.closest('div') as HTMLElement | null;
        if (parent && parent.style.display !== 'none') el.click();
    });
}

function ignoreAll(): void {
    document.querySelectorAll<HTMLElement>('.ignoreExtension').forEach((el) => {
        const parent = el.closest('div') as HTMLElement | null;
        if (parent && parent.style.display !== 'none') el.click();
    });
}

function resetIgnored(): void {
    fetch(
        config.wsUrl +
            new URLSearchParams({
                method: 'pwg.extensions.ignoreUpdate',
                reset: 'true',
                type: pageData.ext_type,
                pwg_token: pageData.pwg_token,
                format: 'json',
            }).toString()
    )
        .then((r) => r.json())
        .then((data: Record<string, unknown>) => {
            if (data['stat'] === 'ok') {
                document.querySelectorAll<HTMLElement>('.pluginBox, fieldset').forEach((el) => {
                    el.style.display = '';
                });
                document
                    .querySelectorAll<HTMLElement>('.pluginBox')
                    .forEach((el) => el.setAttribute('data-ignored', 'false'));
                ['update_all', 'ignore_all'].forEach((id) => {
                    const el = document.getElementById(id);
                    if (el) el.style.display = '';
                });
                ['up_to_date', 'reset_ignore', 'ignored'].forEach((id) => {
                    const el = document.getElementById(id);
                    if (el) el.style.display = 'none';
                });
                checkFieldsets();
            }
        });
}

// Event delegation for action links inside extension boxes.
document.addEventListener('click', (e) => {
    const target = e.target as HTMLElement | null;
    if (!target) return;

    const upd = target.closest<HTMLElement>('.updateExtension');
    if (upd) {
        e.preventDefault();
        const type = upd.dataset['extType'] ?? '';
        const id = upd.dataset['extId'] ?? '';
        const revision = upd.dataset['revisionId'] ?? '';
        updateExtension(type, id, revision);
        return;
    }

    const ign = target.closest<HTMLElement>('.ignoreExtension');
    if (ign) {
        e.preventDefault();
        const type = ign.dataset['extType'] ?? '';
        const id = ign.dataset['extId'] ?? '';
        ignoreExtension(type, id);
        return;
    }
});

document.getElementById('update_all')?.addEventListener('click', () => {
    if (window.confirm(pageData.str_confirm_update_all)) {
        updateAll();
    }
});

document.getElementById('ignore_all')?.addEventListener('click', (e) => {
    e.preventDefault();
    ignoreAll();
});

document.getElementById('reset_ignore')?.addEventListener('click', (e) => {
    e.preventDefault();
    resetIgnored();
});

checkFieldsets();
