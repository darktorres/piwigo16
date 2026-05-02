import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import { getPageData } from './page-data';

interface PluginsInstalledPageData {
    pwg_token: string;
    isWebmaster: number;
    show_details: boolean;
    nb_plugin: { all: number; active: number; inactive: number; other: number };
    activate_msg: string;
    cancel_msg: string;
    confirm_msg: string;
    deactivate_all_msg: string;
    delete_plugin_msg: string;
    deleted_plugin_msg: string;
    incompatible_msg: string;
    not_webmaster: string;
    nothing_found: string;
    plugin_action_error: string;
    plugin_added_str: string;
    plugin_deactivated_str: string;
    plugin_found: string;
    plugin_restored_str: string;
    restore_plugin_msg: string;
    str_restore_def: string;
    uninstall_plugin_msg: string;
    x_plugins_found: string;
}

const {
    pwg_token,
    isWebmaster,
    show_details,
    nb_plugin,
    activate_msg,
    cancel_msg,
    confirm_msg,
    deactivate_all_msg,
    delete_plugin_msg,
    deleted_plugin_msg,
    incompatible_msg,
    not_webmaster,
    nothing_found,
    plugin_action_error,
    plugin_added_str,
    plugin_deactivated_str,
    plugin_found,
    plugin_restored_str,
    restore_plugin_msg,
    str_restore_def,
    uninstall_plugin_msg,
    x_plugins_found,
} = getPageData<PluginsInstalledPageData>();

const complete = false;
const i = 0;
const plugin_filter: string | null = new URLSearchParams(window.location.search).get('filter');

const qsa = <T extends HTMLElement = HTMLElement>(sel: string) =>
    Array.from(document.querySelectorAll<T>(sel));
const qs = <T extends HTMLElement = HTMLElement>(sel: string) => document.querySelector<T>(sel);

class AjaxQueue {
    private pending: Array<() => Promise<void>> = [];
    private running = 0;
    private max: number;
    constructor(max: number) {
        this.max = max;
    }
    add(fn: () => Promise<void>): void {
        this.pending.push(fn);
        this.process();
    }
    private process(): void {
        while (this.running < this.max && this.pending.length > 0) {
            const fn = this.pending.shift()!;
            this.running++;
            fn().finally(() => {
                this.running--;
                this.process();
            });
        }
    }
}

const queuedManager = new AjaxQueue(1);
const nb_plugins = document.querySelectorAll('div.active').length;
let done = 0;

function setDisplay(
    form: string,
    desc: boolean,
    actions: boolean,
    smallIcons: boolean,
    compact: boolean
) {
    qsa('.pluginContainer').forEach((el) => {
        el.classList.remove('line-form', 'compact-form', 'classic-form');
        if (form) el.classList.add(form);
    });
    qsa('.pluginDesc').forEach((el) => {
        el.style.display = desc ? '' : 'none';
    });
    qsa('.pluginActions').forEach((el) => {
        el.style.display = actions ? '' : 'none';
    });
    qsa('.pluginActionsSmallIcons').forEach((el) => {
        el.style.display = smallIcons ? '' : 'none';
    });
    qsa('.pluginName').forEach((el) => {
        el.classList.toggle('pluginNameCompact', compact);
    });
}

function setDisplayClassic() {
    setDisplay('classic-form', true, true, false, false);
}
function setDisplayCompact() {
    setDisplay('compact-form', false, false, true, true);
}
function setDisplayLine() {
    setDisplay('line-form', true, true, false, false);
}

function showPluginError(id: string) {
    const errEl = document.querySelector<HTMLElement>(`#${id} .PluginActionError`);
    if (!errEl) return;
    errEl.style.display = 'flex';
    setTimeout(() => {
        errEl.style.transition = 'opacity 2.5s';
        errEl.style.opacity = '0';
        setTimeout(() => {
            errEl.style.display = 'none';
            errEl.style.opacity = '';
            errEl.style.transition = '';
        }, 2500);
    }, 1500);
}

function fadeOut(el: HTMLElement | null, durationMs: number) {
    if (!el) return;
    el.style.transition = `opacity ${durationMs / 1000}s`;
    el.style.opacity = '0';
    setTimeout(() => {
        el.style.display = 'none';
        el.style.opacity = '';
        el.style.transition = '';
    }, durationMs);
}

function pluginAction(id: string, action: string): Promise<any> {
    const params = new URLSearchParams({
        method: 'pwg.plugins.performAction',
        action,
        plugin: id,
        pwg_token,
        format: 'json',
    });
    return fetch('ws.php?' + params).then((r) => r.json());
}

function activatePlugin(id: string) {
    document.querySelector<HTMLInputElement>(`#${id} .switch`)!.disabled = true;
    pluginAction(id, 'activate')
        .then((data) => {
            if (data.stat == 'ok') {
                const notif = document.querySelector<HTMLElement>(
                    `#${id} .AddPluginSuccess label span:first-child`
                );
                if (notif) notif.innerHTML = plugin_added_str;
                const success = document.querySelector<HTMLElement>(`#${id} .AddPluginSuccess`);
                if (success) success.style.display = 'flex';
                nb_plugin.active += 1;
                nb_plugin.inactive -= 1;
                actualizeFilter();
            }
        })
        .catch((e) => {
            document.querySelector<HTMLElement>(
                `#${id} .PluginActionError label span:first-child`
            )!.innerHTML = plugin_action_error;
            showPluginError(id);
        })
        .finally(() => {
            document.querySelector<HTMLInputElement>(`#${id} .switch`)!.disabled = false;
            setTimeout(
                () =>
                    fadeOut(document.querySelector<HTMLElement>(`#${id} .AddPluginSuccess`), 3000),
                0
            );
        });
}

function disactivatePlugin(id: string) {
    document.querySelector<HTMLInputElement>(`#${id} .switch`)!.disabled = true;
    pluginAction(id, 'deactivate')
        .then((data) => {
            if (data.stat == 'ok') {
                const notif = document.querySelector<HTMLElement>(
                    `#${id} .DeactivatePluginSuccess label span:first-child`
                );
                if (notif) notif.innerHTML = plugin_deactivated_str;
                const success = document.querySelector<HTMLElement>(
                    `#${id} .DeactivatePluginSuccess`
                );
                if (success) success.style.display = 'flex';
                nb_plugin.inactive += 1;
                nb_plugin.active -= 1;
                actualizeFilter();
            }
        })
        .catch((e) => {
            document.querySelector<HTMLElement>(
                `#${id} .PluginActionError label span:first-child`
            )!.innerHTML = plugin_action_error;
            showPluginError(id);
        })
        .finally(() => {
            document.querySelector<HTMLInputElement>(`#${id} .switch`)!.disabled = false;
            setTimeout(
                () =>
                    fadeOut(
                        document.querySelector<HTMLElement>(`#${id} .DeactivatePluginSuccess`),
                        3000
                    ),
                0
            );
        });
}

function deletePlugin(id: string, name: string) {
    pluginAction(id, 'delete')
        .then((data) => {
            if (data.stat === 'ok') {
                document.getElementById(id)?.remove();
                nb_plugin.inactive -= 1;
                nb_plugin.all -= 1;
                actualizeFilter();
                window.alert(deleted_plugin_msg.replace('%s', name));
            }
        })
        .catch((e) => {
            document.querySelector<HTMLElement>(
                `#${id} .PluginActionError label span:first-child`
            )!.innerHTML = plugin_action_error;
            showPluginError(id);
        });
}

function restorePlugin(id: string) {
    pluginAction(id, 'restore')
        .then((data) => {
            if (data.stat == 'ok') {
                const notif = document.querySelector<HTMLElement>(
                    `#${id} .RestorePluginSuccess label span:first-child`
                );
                if (notif) notif.innerHTML = plugin_restored_str;
                const success = document.querySelector<HTMLElement>(`#${id} .RestorePluginSuccess`);
                if (success) success.style.display = 'flex';
            }
        })
        .catch((e) => {
            document.querySelector<HTMLElement>(
                `#${id} .PluginActionError label span:first-child`
            )!.innerHTML = plugin_action_error;
            showPluginError(id);
        })
        .finally(() =>
            setTimeout(
                () =>
                    fadeOut(
                        document.querySelector<HTMLElement>(`#${id} .RestorePluginSuccess`),
                        3000
                    ),
                0
            )
        );
}

function uninstallPlugin(id: string) {
    pluginAction(id, 'uninstall')
        .then((data) => {
            document.getElementById(id)?.remove();
            nb_plugin.other -= 1;
            nb_plugin.all -= 1;
            actualizeFilter();
        })
        .catch((e) => {
            document.querySelector<HTMLElement>(
                `#${id} .PluginActionError label span:first-child`
            )!.innerHTML = plugin_action_error;
            showPluginError(id);
        });
}

function set_view_selector(view_type: string) {
    fetch('ws.php?format=json&method=pwg.users.preferences.set', {
        method: 'POST',
        body: new URLSearchParams({ param: 'plugin-manager-view', value: view_type }),
    });
}

function performPluginDeactivate(id: string) {
    queuedManager.add(() =>
        pluginAction(id, 'deactivate').then((data) => {
            if (data.stat == 'ok') {
                const el = document.getElementById(id);
                el?.classList.remove('active');
                el?.classList.add('inactive');
            }
            done++;
            if (done == nb_plugins) location.reload();
        })
    );
}

function showInactivePlugins() {
    qsa('.showInactivePlugins').forEach((el) => {
        el.style.display = 'none';
    });
    qsa('.plugin-inactive').forEach((el) => {
        el.style.display = '';
    });
}

function actualizeFilter() {
    const setBadge = (sel: string, val: any) => {
        const el = qs(sel);
        if (el) el.innerHTML = String(val);
    };
    setBadge("label[for='seeAll'] .filter-badge", nb_plugin.all);
    setBadge("label[for='seeActive'] .filter-badge", nb_plugin.active);
    setBadge("label[for='seeInactive'] .filter-badge", nb_plugin.inactive);
    setBadge("label[for='seeOther'] .filter-badge", nb_plugin.other);
    qsa<HTMLElement>('.filterLabel').forEach((el) => {
        el.style.display = '';
    });

    const seeActive = qs<HTMLInputElement>('#seeActive');
    const seeInactive = qs<HTMLInputElement>('#seeInactive');
    const seeOther = qs<HTMLInputElement>('#seeOther');
    const seeAll = qs<HTMLElement>('#seeAll');

    const checkAndReset = (count: number, labelFor: string, radio: HTMLInputElement | null) => {
        const label = qs<HTMLElement>(`label[for='${labelFor}']`);
        if (count == 0) {
            if (label) label.style.display = 'none';
            if (radio?.checked) seeAll?.click();
        }
    };
    checkAndReset(nb_plugin.active, 'seeActive', seeActive);
    checkAndReset(nb_plugin.inactive, 'seeInactive', seeInactive);
    checkAndReset(nb_plugin.other, 'seeOther', seeOther);
}

function filterPlugins(text: string, activeFilter: string) {
    let searchNumber = 0,
        searchActive = 0,
        searchInactive = 0,
        searchOther = 0;

    qsa('.pluginBox').forEach((box) => {
        const name =
            box.querySelector<HTMLElement>('.pluginName')?.textContent?.toLowerCase() ?? '';
        const desc =
            box.querySelector<HTMLElement>('.pluginDesc')?.textContent?.toLowerCase() ?? '';
        const isActive = box.classList.contains('plugin-active');
        const isInactive = box.classList.contains('plugin-inactive');
        const isOther =
            box.classList.contains('plugin-merged') || box.classList.contains('plugin-missing');

        const matchesText = text === '' || name.includes(text) || desc.includes(text);
        const matchesFilter =
            activeFilter === 'seeAll' ||
            (activeFilter === 'seeActive' && isActive) ||
            (activeFilter === 'seeInactive' && isInactive) ||
            (activeFilter === 'seeOther' && isOther);

        box.style.display = matchesText && matchesFilter ? '' : 'none';
        if (matchesText && matchesFilter) {
            searchNumber++;
            if (isActive) searchActive++;
            if (isInactive) searchInactive++;
            if (isOther) searchOther++;
        }
    });

    nb_plugin.all = searchNumber;
    nb_plugin.active = searchActive;
    nb_plugin.inactive = searchInactive;
    nb_plugin.other = searchOther;

    const searchInfoEl = qs<HTMLElement>('.nbPluginsSearch');
    if (!searchInfoEl) return;
    if (text === '') {
        searchInfoEl.style.display = 'none';
    } else {
        searchInfoEl.style.display = '';
        searchInfoEl.innerHTML =
            searchNumber === 0
                ? nothing_found
                : searchNumber === 1
                  ? plugin_found.replace('%s', String(searchNumber))
                  : x_plugins_found.replace('%s', String(searchNumber));
    }
    actualizeFilter();
}

document.addEventListener('DOMContentLoaded', () => {
    actualizeFilter();

    if (qs<HTMLInputElement>('#displayClassic')?.checked) setDisplayClassic();
    if (qs<HTMLInputElement>('#displayCompact')?.checked) setDisplayCompact();
    if (qs<HTMLInputElement>('#displayLine')?.checked) setDisplayLine();

    qs('#displayClassic')?.addEventListener('change', () => {
        setDisplayClassic();
        set_view_selector('classic');
    });
    qs('#displayCompact')?.addEventListener('change', () => {
        setDisplayCompact();
        set_view_selector('compact');
    });
    qs('#displayLine')?.addEventListener('change', () => {
        setDisplayLine();
        set_view_selector('line');
    });

    if (nb_plugin.active > 0) {
        qsa('.pluginMiniBox').forEach((el) => {
            if (!el.classList.contains('plugin-active')) el.style.display = 'none';
        });
        qs<HTMLElement>('#seeActive')?.click();
    } else {
        qsa<HTMLElement>('.pluginMiniBox').forEach((el) => {
            el.style.display = '';
        });
    }

    let activeFilter = 'seeAll';
    const filterRadios = ['seeAll', 'seeActive', 'seeInactive', 'seeOther'];
    filterRadios.forEach((id) => {
        qs(`#${id}`)?.addEventListener('change', () => {
            activeFilter = id;
            const searchText = String(
                qs<HTMLInputElement>('.pluginFilter input.search-input')?.value ?? ''
            ).toLowerCase();
            filterPlugins(searchText, activeFilter);
        });
    });

    if (isWebmaster != 0) {
        qsa<HTMLElement>('.switch').forEach((switchEl) => {
            switchEl.addEventListener('change', function (this: HTMLElement) {
                qsa('.pluginMiniBox').forEach((el) => el.classList.add('usable'));
                const pluginBox = this.parentElement?.parentElement as HTMLElement;
                const pluginId = pluginBox?.id ?? '';
                const toggleInput = this.querySelector<HTMLInputElement>('#toggleSelectionMode');
                if (toggleInput?.checked) {
                    activatePlugin(pluginId);
                    pluginBox.classList.add('plugin-active');
                    pluginBox.classList.remove('plugin-inactive');
                    const unavail = pluginBox.querySelector<HTMLElement>(
                        '.pluginUnavailableAction'
                    );
                    if (unavail?.getAttribute('href')) {
                        unavail.classList.remove('pluginUnavailableAction');
                        unavail.classList.add('pluginActionLevel1');
                    }
                } else {
                    disactivatePlugin(pluginId);
                    pluginBox.classList.remove('plugin-active');
                    pluginBox.classList.add('plugin-inactive');
                    pluginBox.querySelectorAll<HTMLElement>('.pluginActionLevel1').forEach((el) => {
                        el.classList.remove('pluginActionLevel1');
                        el.classList.add('pluginUnavailableAction');
                    });
                }
                actualizeFilter();
            });
        });
    } else {
        qsa('.pluginMiniBox').forEach((el) => el.classList.add('notUsable'));
        qsa('.plugin-active .slider').forEach((el) => el.classList.add('desactivate_disabled'));
        qsa('.plugin-inactive .slider').forEach((el) => el.classList.add('activate_disabled'));
        qsa('.switch input').forEach((input) => {
            input.addEventListener('click', (e) => {
                input.classList.add('disabled');
                e.preventDefault();
                e.stopPropagation();
                const id = input.parentElement?.parentElement?.parentElement?.id ?? '';
                const errSpan = document.querySelector<HTMLElement>(
                    `#${id} .PluginActionError label span:first-child`
                );
                if (errSpan) errSpan.innerHTML = not_webmaster;
                showPluginError(id);
                setTimeout(
                    () =>
                        qsa<HTMLInputElement>('.switch input').forEach((el) =>
                            el.classList.remove('disabled')
                        ),
                    400
                );
            });
        });
    }

    qsa('.pluginContent .dropdown-option.delete-plugin-button').forEach((btn) => {
        btn.addEventListener('click', () => {
            const plugin_name =
                btn
                    .closest<HTMLElement>('.pluginContent')
                    ?.querySelector<HTMLElement>('.pluginName')
                    ?.innerHTML.trim() ?? '';
            const plugin_id = btn.closest<HTMLElement>('.pluginContent')?.parentElement?.id ?? '';
            if (window.confirm(delete_plugin_msg.replace('%s', plugin_name)))
                deletePlugin(plugin_id, plugin_name);
        });
    });

    qsa('.pluginContent .dropdown-option.plugin-restore').forEach((btn) => {
        btn.addEventListener('click', () => {
            const plugin_name =
                btn
                    .closest<HTMLElement>('.pluginContent')
                    ?.querySelector<HTMLElement>('.pluginName')
                    ?.innerHTML.trim() ?? '';
            const plugin_id = btn.closest<HTMLElement>('.pluginContent')?.parentElement?.id ?? '';
            const msg =
                restore_plugin_msg.replace('%s', plugin_name) +
                (str_restore_def ? '\n\n' + str_restore_def : '');
            if (window.confirm(msg)) restorePlugin(plugin_id);
        });
    });

    qsa('.pluginContent .uninstall-plugin-button').forEach((btn) => {
        btn.addEventListener('click', () => {
            const plugin_name =
                btn
                    .closest<HTMLElement>('.pluginContent')
                    ?.querySelector<HTMLElement>('.pluginName')
                    ?.innerHTML.trim() ?? '';
            const plugin_id = btn.closest<HTMLElement>('.pluginContent')?.parentElement?.id ?? '';
            if (window.confirm(uninstall_plugin_msg.replace('%s', plugin_name)))
                uninstallPlugin(plugin_id);
        });
    });

    qs('div.deactivate_all a')?.addEventListener('click', () => {
        if (!window.confirm(deactivate_all_msg)) return;
        document
            .querySelectorAll<HTMLElement>('div.active')
            .forEach((el) => performPluginDeactivate(el.id));
    });

    fetch(
        'admin.php?' +
            new URLSearchParams({ page: 'plugins_installed', incompatible_plugins: 'true' })
    )
        .then((r) => r.json())
        .then((data: string[]) => {
            data.forEach((pluginId) => {
                const nameEl = document.querySelector(`#${pluginId} .pluginName`);
                if (nameEl) {
                    const tag = show_details
                        ? `<a class="warning" title="${incompatible_msg}"></a>`
                        : `<span class="warning" title="${incompatible_msg}"></span>`;
                    nameEl.insertAdjacentHTML('afterbegin', tag);
                }
                document.getElementById(pluginId)?.classList.add('incompatible');
                document.querySelectorAll<HTMLElement>(`#${pluginId} .activate`).forEach((el) => {
                    (window as any).pwg_jconfirm_follow_href_fn(el, {
                        alert_title: incompatible_msg + activate_msg,
                        alert_confirm: confirm_msg,
                        alert_cancel: cancel_msg,
                    });
                });
            });
            tippy('.warning', { delay: [0, 0], duration: [200, 200], maxWidth: 250 });
        });

    tippy('.fullInfo', { delay: [500, 0], duration: [200, 200], maxWidth: 300 });

    document.onkeydown = (e) => {
        if (e.keyCode == 58) {
            qs<HTMLInputElement>('.pluginFilter input.search-input')?.focus();
            return false;
        }
        return undefined;
    };

    qs('.pluginFilter input')?.addEventListener('input', function (this: HTMLInputElement) {
        const text = this.value.toLowerCase();
        filterPlugins(text, activeFilter);
    });

    qsa('.pluginBox').forEach((box) => {
        box.querySelector<HTMLElement>('.showOptions')?.addEventListener('click', () => {
            const optBlock = box.querySelector<HTMLElement>('.PluginOptionsBlock');
            if (optBlock) optBlock.style.display = optBlock.style.display === 'none' ? '' : 'none';
        });
    });

    qs<HTMLElement>('.showInactivePlugins button')?.addEventListener('click', showInactivePlugins);

    if (plugin_filter === 'deactivated') {
        qs<HTMLElement>(".filterLabel[for='seeInactive']")?.click();
    }
});

document.addEventListener('mouseup', (e: MouseEvent) => {
    e.stopPropagation();
    const target = e.target as Element;
    qsa('.pluginBox').forEach((box) => {
        if (!box.querySelector('.showOptions')?.contains(target)) {
            const block = box.querySelector<HTMLElement>('.PluginOptionsBlock');
            if (block) block.style.display = 'none';
        }
    });
});

export {};
