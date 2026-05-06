import { getPageData } from './page-data';
import { config } from '../../default/js/config';

interface ProfilePageData {
    canUpdatePreferences: boolean;
    canUpdatePassword: boolean;
    can_manage_api: boolean;
    user: {
        username: string;
        email: string;
        nb_image_page: string;
        theme: string;
        language: string;
        recent_period: string;
        opt_album: boolean;
        opt_comment: boolean;
        opt_hits: boolean;
    };
    preferencesDefaultValues: {
        nb_image_page: string | null;
        recent_period: string | null;
        opt_album: boolean;
        opt_comment: boolean;
        opt_hits: boolean;
    };
    standardSaveSelector: string[];
    selected_date: string;
    no_time_elapsed: string;
    str_handle_error: string;
    str_copy_key_secret: string;
    str_copy_key_id: string;
    str_api_edited: string;
    str_api_revoked: string;
    str_api_added: string;
    str_revoke_key: string;
    str_cant_copy: string;
    str_show_expired: string;
    str_hide_expired: string;
}

const {
    canUpdatePreferences,
    canUpdatePassword,
    can_manage_api,
    user,
    preferencesDefaultValues,
    standardSaveSelector,
    selected_date,
    no_time_elapsed,
    str_handle_error,
    str_copy_key_secret,
    str_copy_key_id,
    str_api_edited,
    str_api_revoked,
    str_api_added,
    str_revoke_key,
    str_cant_copy,
    str_show_expired,
    str_hide_expired,
} = getPageData<ProfilePageData>();

declare function pwgToaster(info: { text: string; icon: 'success' | 'error' }): void;
declare function sprintf(fmt: string, ...args: unknown[]): string;

let PWG_TOKEN = '';
let apiKeyAbort: AbortController | null = null;

const qs = <T extends HTMLElement = HTMLElement>(sel: string) => document.querySelector<T>(sel);
const qsa = <T extends HTMLElement = HTMLElement>(sel: string) =>
    Array.from(document.querySelectorAll<T>(sel));

document.addEventListener('DOMContentLoaded', () => {
    PWG_TOKEN = String((document.getElementById('pwg_token') as HTMLInputElement)?.value ?? '');

    qsa('.profile-section .display-section').forEach((btn) => {
        btn.addEventListener('click', () => {
            const display = btn.dataset['display']!;
            const section = document.getElementById(display)!;
            const arrow = btn.querySelector<HTMLElement>('.display-btn');
            if (section.classList.contains('open')) {
                section.style.maxHeight = section.scrollHeight + 'px';
                void section.offsetHeight;
                section.style.maxHeight = '1px';
                section.classList.remove('open');
                arrow?.classList.add('close');
            } else {
                section.classList.add('open');
                resetSection(display);
                arrow?.classList.remove('close');
            }
        });
    });

    setTimeout(() => qs('#account-section .display-section')?.click(), 100);

    qs('#save_account')?.addEventListener('click', () => {
        const mail = qs<HTMLInputElement>('#email')?.value;
        if (!mail || mail === '') {
            qs('#email_error')!.style.display = '';
            return;
        }
        setInfos({ email: mail });
    });

    if (canUpdatePreferences) {
        qs('#save_preferences')?.addEventListener('click', () => {
            const values: Record<string, unknown> = {
                nb_image_page: qs<HTMLInputElement>('#nb_image_page')?.value,
                theme: qs<HTMLSelectElement>('select[name="theme"]')?.value,
                language: qs<HTMLSelectElement>('select[name="language"]')?.value,
                recent_period: qs<HTMLInputElement>('#recent_period')?.value,
                expand: qs<HTMLInputElement>('#opt_album')?.checked,
                show_nb_comments: qs<HTMLInputElement>('#opt_comment')?.checked,
                show_nb_hits: qs<HTMLInputElement>('#opt_hits')?.checked,
            };
            if (!values.nb_image_page) {
                qs('#error_nb_image')!.style.display = '';
                return;
            }
            if (!values.recent_period) {
                qs('#error_period')!.style.display = '';
                return;
            }
            setInfos(values);
        });

        qs('#reset_preferences')?.addEventListener('click', () => {
            const u = user as any;
            qs<HTMLInputElement>('input[name="nb_image_page"]')!.value = u.nb_image_page;
            qs<HTMLSelectElement>('select[name="theme"]')!.value = u.theme;
            qs<HTMLSelectElement>('select[name="language"]')!.value = u.language;
            qs<HTMLInputElement>('input[name="recent_period"]')!.value = u.recent_period;
            qs<HTMLInputElement>('#opt_album')!.checked = u.opt_album;
            qs<HTMLInputElement>('#opt_comment')!.checked = u.opt_comment;
            qs<HTMLInputElement>('#opt_hits')!.checked = u.opt_hits;
        });

        qs('#default_preferences')?.addEventListener('click', () => {
            const d = preferencesDefaultValues as any;
            qs<HTMLInputElement>('input[name="nb_image_page"]')!.value = d.nb_image_page;
            qs<HTMLInputElement>('input[name="recent_period"]')!.value = d.recent_period;
            qs<HTMLInputElement>('#opt_album')!.checked = d.opt_album;
            qs<HTMLInputElement>('#opt_comment')!.checked = d.opt_comment;
            qs<HTMLInputElement>('#opt_hits')!.checked = d.opt_hits;
        });
    }

    if (canUpdatePassword) {
        qs('#save_password')?.addEventListener('click', () => {
            const passwords: Record<string, unknown> = {
                password: qs<HTMLInputElement>('#password')?.value,
                new_password: qs<HTMLInputElement>('#password_new')?.value,
                conf_new_password: qs<HTMLInputElement>('#password_conf')?.value,
            };
            if (!passwords.password || !passwords.new_password || !passwords.conf_new_password) {
                qsa<HTMLInputElement>('#password-section input').forEach((el) => {
                    if (!el.value) el.parentElement?.nextElementSibling?.removeAttribute('style');
                });
                return;
            }
            setInfos(passwords);
            qsa<HTMLInputElement>('#password-section input').forEach((el) => {
                el.value = '';
            });
        });
    }

    // Plugins that opt into standard_show_save render their own
    // <button id="save_<block>" data-standard-save="<block>">. Bind them all.
    document.querySelectorAll<HTMLButtonElement>('button[data-standard-save]').forEach((btn) => {
        const blockKey = btn.dataset['standardSave'] ?? '';
        btn.addEventListener('click', () => {
            const values: Record<string, unknown> = {};
            document
                .querySelectorAll<
                    HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
                >(`#${blockKey}-section input, #${blockKey}-section textarea, #${blockKey}-section select`)
                .forEach((el) => {
                    if (el.name) values[el.name] = el.value;
                });
            setInfos(values);
        });
    });

    if (!can_manage_api) {
        qsa('.can-manage').forEach((el) => {
            el.style.display = 'none';
        });
        qs('#cant_manage_api')!.style.display = '';
        return;
    }

    qs('#new_apikey')?.addEventListener('click', openApiModal);
    qs('#close_api_modal')?.addEventListener('click', closeApiModal);
    qs('#cancel_apikey')?.addEventListener('click', closeApiModal);
    qs('#close_api_modal_edit')?.addEventListener('click', closeApiEditModal);
    qs('#close_api_modal_revoke')?.addEventListener('click', closeApiRevokeModal);
    qs('#cancel_api_revoke')?.addEventListener('click', closeApiRevokeModal);

    qs('#show_expired_list')?.addEventListener('click', function (this: HTMLElement) {
        const api_list_expired = qs('#api_key_list_expired')!;
        const isOpen = this.dataset['show'] === 'true';
        api_list_expired.style.maxHeight = isOpen ? '0' : 'max-content';
        this.textContent = isOpen ? str_show_expired : str_hide_expired;
        this.dataset['show'] = String(!isOpen);
        resetSection('apikey-display', false, true);
    });

    window.addEventListener('keydown', (e: KeyboardEvent) => {
        if (qs('#api_modal')?.style.display !== 'none' && e.key === 'Escape') closeApiModal();
        if (qs('#api_modal_edit')?.style.display !== 'none' && e.key === 'Escape')
            closeApiEditModal();
        if (qs('#api_modal_revoke')?.style.display !== 'none' && e.key === 'Escape')
            closeApiRevokeModal();
    });

    qs<HTMLSelectElement>('select[name="api_expiration"]')?.addEventListener(
        'change',
        function (this: HTMLSelectElement) {
            const custom_date = qs('#api_custom_date')!;
            custom_date.style.display = this.value === 'custom' ? 'flex' : 'none';
            qs('#error_api_key_date')!.style.display = 'none';
        }
    );

    qs('#api_expiration_date')?.addEventListener('change', () => {
        qs('#error_api_key_date')!.style.display = 'none';
    });

    getAllApiKeys();
});

function setInfos(
    params: Record<string, unknown>,
    method = 'pwg.users.setMyInfo',
    callback: ((res: unknown) => void) | null = null,
    errCallback: ((err: unknown) => void) | null = null
): void {
    const body = new URLSearchParams();
    for (const [k, v] of Object.entries({ ...params, pwg_token: PWG_TOKEN }))
        body.append(k, String(v ?? ''));
    fetch(`${config.wsUrl}?format=json&method=${method}`, { method: 'POST', body })
        .then((r) => r.json())
        .then((data: any) => {
            if (data.stat === 'ok') {
                (window as any).user = Object.assign({}, user as object, params);
                if (typeof callback === 'function') {
                    callback(data.result);
                    return;
                }
                pwgToaster({ text: data.result, icon: 'success' });
            } else if (data.stat === 'fail') {
                pwgToaster({ text: data.message, icon: 'error' });
            } else {
                pwgToaster({ text: str_handle_error, icon: 'error' });
            }
            if (typeof errCallback === 'function') errCallback(data);
        })
        .catch((e: any) => {
            pwgToaster({ text: e.message ?? str_handle_error, icon: 'error' });
            if (typeof errCallback === 'function') errCallback(e);
        });
}

function getAllApiKeys(reset = false): void {
    fetch(config.wsUrl + '?format=json&method=pwg.users.api_key.get', {
        method: 'POST',
        body: new URLSearchParams({ pwg_token: PWG_TOKEN }),
    })
        .then((r) => r.json())
        .then((res: any) => {
            if (res.stat === 'ok' && typeof res.result !== 'string' && res.result !== false)
                AddApiLine(res.result, reset);
        })
        .catch((e: any) =>
            pwgToaster({ text: e.message ?? str_handle_error + 'getAllApiKeys', icon: 'error' })
        );
}

function AddApiLine(lines: any[], reset: boolean): void {
    const api_list = qs('#api_key_list')!;
    const api_list_expired = qs('#api_key_list_expired')!;
    qsa(
        '#api_key_list .api-tab-line:not(.template-api), #api_key_list .api-tab-collapse:not(.template-api)'
    ).forEach((el) => el.remove());
    qsa(
        '#api_key_list_expired .api-tab-line:not(.template-api), #api_key_list_expired .api-tab-collapse:not(.template-api)'
    ).forEach((el) => el.remove());

    lines.forEach((line) => {
        const api_line = document.getElementById('api_line')!.cloneNode(true) as HTMLElement;
        const api_collapse = document
            .getElementById('api_collapse')!
            .cloneNode(true) as HTMLElement;
        const tmp_id = String(line.auth_key).slice(24, 34);

        api_line.classList.remove('template-api');
        api_line.classList.add('api-tab');
        api_line.id = `api_${tmp_id}`;
        const collapseIcon = api_line.querySelector<HTMLElement>('.icon-collapse');
        if (collapseIcon) collapseIcon.dataset['api'] = tmp_id;
        api_line.querySelector<HTMLElement>('.api_name')!.textContent = line.apikey_name;
        api_line.querySelector<HTMLElement>('.api_name')!.title = line.apikey_name;
        api_line.querySelector<HTMLElement>('.api_creation')!.textContent = line.created_on_format;
        const lastUse = api_line.querySelector<HTMLElement>('.api_last_use')!;
        lastUse.textContent = line.last_used_on_since;
        lastUse.title = line.last_used_on_since;
        api_line.querySelector<HTMLElement>('.api_expiration')!.textContent = line.expiration;
        api_line.querySelectorAll<HTMLElement>('.api-icon-action').forEach((el) => {
            el.dataset['api'] = `api_${tmp_id}`;
            el.dataset['pkid'] = line.auth_key;
        });

        api_collapse.id = `api_collapse_${tmp_id}`;
        api_collapse.classList.remove('template-api');
        api_collapse.querySelector<HTMLElement>('.api_key')!.textContent = line.auth_key;
        const cloneIcon = api_collapse.querySelector<HTMLElement>('.icon-clone');
        if (cloneIcon) {
            cloneIcon.dataset['copy'] = line.auth_key;
            cloneIcon.dataset['success'] = `api_copy_success_${tmp_id}`;
        }
        const apiCopy = api_collapse.querySelector<HTMLElement>('.api-copy');
        if (apiCopy) apiCopy.id = `api_copy_success_${tmp_id}`;

        if (!line.revoked_on && !line.is_expired) {
            api_list.appendChild(api_line);
            api_line.after(api_collapse);
        } else {
            qs('#show_expired_list')!.style.display = '';
            api_list_expired.appendChild(api_line);
            api_line.after(api_collapse);
            api_line.querySelectorAll('.api-icon-action').forEach((el) => el.remove());
            const expEl = api_line.querySelector<HTMLElement>('.api_expiration')!;
            if (line.is_expired) {
                expEl.innerHTML = `<i class="gallery-icon-skull api-skull"></i> <span data-tooltip="${line.expired_on_format}">${line.expired_on_since}</span>`;
            } else {
                expEl.innerHTML = `<i class="gallery-icon-skull api-skull"></i> <span>${/\d/.test(line.revoked_on_since) ? line.revoked_on_since : no_time_elapsed}</span> <i data-tooltip="${line.revoked_on_message}" class="icon-info-circled-1 api-info"></i>`;
            }
        }
    });

    apiLineEvent();
    if (reset) resetSection('apikey-display');
}

function apiLineEvent(): void {
    qsa('.icon-collapse').forEach((el) => {
        const newEl = el.cloneNode(true) as HTMLElement;
        el.replaceWith(newEl);
        newEl.addEventListener('click', () => {
            const apiId = newEl.dataset['api'];
            const collapse = document.getElementById(`api_collapse_${apiId}`)!;
            const line = document.getElementById(`api_${apiId}`)!;
            const visible = collapse.style.display !== 'none' && collapse.style.display !== '';
            if (visible) {
                collapse.classList.remove('open');
                collapse.style.display = 'none';
                line.classList.remove('open');
                line.querySelector('.icon-collapse')?.classList.add('close');
                collapse.querySelector<HTMLElement>('.api-copy')?.classList.add('api-hide');
            } else {
                collapse.classList.add('open');
                collapse.style.display = 'grid';
                line.classList.add('open');
                line.querySelector('.icon-collapse')?.classList.remove('close');
            }
            resetSection('apikey-display', false, true);
        });
    });

    qsa('.api-tab-collapse .icon-clone').forEach((el) => {
        const newEl = el.cloneNode(true) as HTMLElement;
        el.replaceWith(newEl);
        newEl.addEventListener('click', () => {
            copyToClipboard(
                String(newEl.dataset['copy']),
                str_copy_key_id,
                `#${newEl.dataset['success']}`
            );
        });
    });

    qsa('.api-tab-line .edit-mode').forEach((el) => {
        const newEl = el.cloneNode(true) as HTMLElement;
        el.replaceWith(newEl);
        newEl.addEventListener('click', () => {
            openApiEditModal(`#${newEl.parentElement?.dataset['api']}`);
        });
    });

    qsa('.api-tab-line .delete-mode').forEach((el) => {
        const newEl = el.cloneNode(true) as HTMLElement;
        el.replaceWith(newEl);
        newEl.addEventListener('click', () => {
            openApiRevokeModal(`#${newEl.parentElement?.dataset['api']}`);
        });
    });
}

function resetSection(selector: string, scroll = true, maxContent = false): void {
    const el = document.getElementById(selector)!;
    el.style.maxHeight = maxContent ? 'max-content' : el.scrollHeight + 'px';
    if (selector !== 'account-display' && scroll) {
        setTimeout(() => {
            document
                .getElementById(selector.split('-')[0] + '-section')
                ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 200);
    }
}

function openApiModal(): void {
    qs('#api_modal')!.style.display = '';
    qs<HTMLInputElement>('#api_key_name')?.focus();
    saveApiKeyEvent();
}

function closeApiModal(): void {
    qs('#api_modal')!.style.display = 'none';
    qs<HTMLInputElement>('#api_key_name')!.value = '';
    const expSelect = qs<HTMLSelectElement>('select[name="api_expiration"]');
    if (expSelect) {
        expSelect.value = selected_date;
        expSelect.dispatchEvent(new Event('change'));
    }
    qs<HTMLInputElement>('#api_expiration_date')!.value = '';
    qs<HTMLInputElement>('#api_secret_key')!.value = '';
    qs('#retrieves_keyapi')!.style.display = 'none';
    qs('#generate_keyapi')!.style.display = '';
    qs<HTMLButtonElement>('#done_apikey')!.disabled = true;
    qsa('#api_key_copy_success, #api_id_copy_success').forEach((el) =>
        el.classList.add('api-hide')
    );
    unbindApiKeyEvents();
}

function successApiModal(secret: string, id: string): void {
    qs<HTMLInputElement>('#api_secret_key')!.value = secret;
    qs<HTMLInputElement>('#api_id_key')!.value = id;
    qs('#generate_keyapi')!.style.display = 'none';
    qs('#retrieves_keyapi')!.style.display = '';
    const secretCopy = qs('#api_secret_copy')!;
    const secretNew = secretCopy.cloneNode(true) as HTMLElement;
    secretCopy.replaceWith(secretNew);
    secretNew.addEventListener('click', () => {
        copyToClipboard(secret, str_copy_key_secret, '#api_key_copy_success');
        const doneBtn = qs<HTMLButtonElement>('#done_apikey')!;
        doneBtn.disabled = false;
        doneBtn.addEventListener('click', closeApiModal, { once: true });
    });
    const idCopy = qs('#api_id_copy')!;
    const idCopyNew = idCopy.cloneNode(true) as HTMLElement;
    idCopy.replaceWith(idCopyNew);
    idCopyNew.addEventListener('click', () =>
        copyToClipboard(id, str_copy_key_id, '#api_id_copy_success')
    );
}

function openApiEditModal(selector: string): void {
    const el = qs(selector);
    const value = el?.querySelector<HTMLElement>('.api_name')?.textContent ?? '';
    const pkid = el?.querySelector<HTMLElement>('.api-icon-action')?.dataset['pkid'];
    qs<HTMLInputElement>('#api_key_edit')!.value = value;
    qs('#api_modal_edit')!.style.display = '';
    qs<HTMLInputElement>('#api_key_edit')?.focus();
    saveApiEditEvents(pkid);
}

function closeApiEditModal(): void {
    qs('#api_modal_edit')!.style.display = 'none';
    qs<HTMLInputElement>('#api_key_edit')!.value = '';
    unbindApiEditEvents();
}

function saveApiEditEvents(pkid: unknown): void {
    const btn = qs('#save_api_edit')!;
    const newBtn = btn.cloneNode(true) as HTMLElement;
    btn.replaceWith(newBtn);
    newBtn.addEventListener('click', () => {
        const value = String(qs<HTMLInputElement>('#api_key_edit')?.value ?? '');
        if (!value) {
            qs('#error_api_key_edit')!.style.display = '';
            return;
        }
        setInfos({ pkid, key_name: value }, 'pwg.users.api_key.edit', () => {
            pwgToaster({ text: str_api_edited, icon: 'success' });
            getAllApiKeys(true);
            closeApiEditModal();
        });
    });
}

function unbindApiEditEvents(): void {
    const btn = qs('#save_api_edit');
    if (btn) {
        const newBtn = btn.cloneNode(true) as HTMLElement;
        btn.replaceWith(newBtn);
    }
}

function openApiRevokeModal(selector: string): void {
    const el = qs(selector);
    const apiName = el?.querySelector<HTMLElement>('.api_name')?.textContent ?? '';
    const pkid = el?.querySelector<HTMLElement>('.api-icon-action')?.dataset['pkid'];
    qs('#api_modal_revoke_title')!.textContent = sprintf(str_revoke_key, apiName);
    qs('#api_modal_revoke')!.style.display = '';
    saveApiRevokeEvents(pkid);
}

function closeApiRevokeModal(): void {
    qs('#api_modal_revoke')!.style.display = 'none';
    qs('#api_modal_revoke_title')!.textContent = '';
    unbindApiRevokeEvents();
}

function saveApiRevokeEvents(pkid: unknown): void {
    const btn = qs('#revoke_api_key')!;
    const newBtn = btn.cloneNode(true) as HTMLElement;
    btn.replaceWith(newBtn);
    newBtn.addEventListener('click', () => {
        setInfos({ pkid }, 'pwg.users.api_key.revoke', () => {
            pwgToaster({ text: str_api_revoked, icon: 'success' });
            getAllApiKeys(true);
            closeApiRevokeModal();
        });
    });
}

function unbindApiRevokeEvents(): void {
    const btn = qs('#revoke_api_key');
    if (btn) {
        const newBtn = btn.cloneNode(true) as HTMLElement;
        btn.replaceWith(newBtn);
    }
}

function copyToClipboard(copy: string, message: string, selector: string | null = null): boolean {
    if (window.isSecureContext && navigator.clipboard) {
        navigator.clipboard.writeText(copy);
        if (selector) qs(selector)?.classList.remove('api-hide');
        else pwgToaster({ text: message, icon: 'success' });
        return true;
    } else {
        pwgToaster({ text: str_cant_copy, icon: 'error' });
        return false;
    }
}

function saveApiKeyEvent(): void {
    apiKeyAbort?.abort();
    apiKeyAbort = new AbortController();
    const { signal } = apiKeyAbort;

    const handler = () => {
        const api_name = String(qs<HTMLInputElement>('#api_key_name')?.value ?? '');
        let api_duration: string | number = String(
            qs<HTMLSelectElement>('select[name="api_expiration"]')?.value ?? ''
        );
        if (!api_name) {
            qs('#error_api_key_name')!.style.display = '';
            return;
        }
        if (api_duration === 'custom' && !qs<HTMLInputElement>('#api_expiration_date')?.value) {
            qs('#error_api_key_date')!.style.display = '';
            return;
        }
        apiKeyAbort?.abort();
        if (api_duration === 'custom') {
            const today = new Date();
            const custom_date = new Date(
                String(qs<HTMLInputElement>('#api_expiration_date')?.value)
            );
            api_duration = Math.ceil(
                (custom_date.getTime() - today.getTime()) / (1000 * 60 * 60 * 24)
            );
        } else {
            api_duration = Number(api_duration) || 1;
        }
        setInfos(
            { key_name: api_name, duration: api_duration },
            'pwg.users.api_key.create',
            (res: unknown) => {
                const r = res as any;
                pwgToaster({ text: str_api_added, icon: 'success' });
                getAllApiKeys(true);
                successApiModal(r.apikey_secret, r.auth_key);
            },
            () => saveApiKeyEvent()
        );
    };

    qs('#save_apikey')?.addEventListener('click', handler, { signal });
    window.addEventListener(
        'keydown',
        (e: KeyboardEvent) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                handler();
            }
        },
        { signal }
    );
}

function unbindApiKeyEvents(): void {
    apiKeyAbort?.abort();
    apiKeyAbort = null;
}

export {};
