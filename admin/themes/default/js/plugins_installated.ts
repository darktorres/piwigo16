import { initModule } from './moduleInit.js';
import tippy from 'tippy.js';
import { pwgConfirm, pwgConfirmFollowHref } from './pwgConfirm.js';

interface NbPlugin { all: number; active: number; inactive: number; other: number }

interface PluginsInstalledConfig {
    pwgToken?: string;
    incompatibleMsg?: string;
    activateMsg?: string;
    deactivateAllMsg?: string;
    nbPlugin?: NbPlugin;
    areYouSureMsg?: string;
    confirmMsg?: string;
    cancelMsg?: string;
    deletePluginMsg?: string;
    deletedPluginMsg?: string;
    restorePluginMsg?: string;
    uninstallPluginMsg?: string;
    restoreTipMsg?: string;
    pluginAddedStr?: string;
    pluginDeactivatedStr?: string;
    pluginRestoredStr?: string;
    pluginActionError?: string;
    notWebmaster?: string;
    nothingFound?: string;
    xPluginsFound?: string;
    pluginFound?: string;
    isWebmaster?: number;
    viewSelector?: string;
    strRestoreDef?: string;
    showDetails?: boolean;
    pluginFilter?: string;
}

let _deactivateQueue: Promise<void> = Promise.resolve();
let _deactivateTotal = 0;
let _deactivateDone = 0;

export function init(cfg: PluginsInstalledConfig): void {
    const {
        pwgToken = '', incompatibleMsg = '', activateMsg = '', deactivateAllMsg = '',
        nbPlugin: nbPluginCfg = { all: 0, active: 0, inactive: 0, other: 0 },
        confirmMsg = 'Yes', cancelMsg = 'No',
        deletePluginMsg = 'Delete %s?', restorePluginMsg = 'Restore %s?',
        uninstallPluginMsg = 'Uninstall %s?',
        pluginAddedStr = '', pluginDeactivatedStr = '', pluginRestoredStr = '',
        pluginActionError: _pluginActionError = '', notWebmaster = '', nothingFound = '',
        xPluginsFound = '%s plugins found', pluginFound = '1 plugin found',
        isWebmaster = 1, strRestoreDef = '', showDetails = false, pluginFilter = '',
    } = cfg;

    const nb_plugin: NbPlugin = { ...nbPluginCfg };
    const pwg_token = pwgToken;

    function showInactivePlugins(): void {
        const showEls = document.querySelectorAll<HTMLElement>('.showInactivePlugins');
        if (!showEls.length) {
            document.querySelectorAll<HTMLElement>('.plugin-inactive').forEach(el => { el.style.display = ''; });
            return;
        }
        let pending = showEls.length;
        showEls.forEach(function (el) {
            void el.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 300, fill: 'forwards' }).finished.then(function () {
                el.style.display = 'none';
                if (--pending === 0) {
                    document.querySelectorAll<HTMLElement>('.plugin-inactive').forEach(function (inEl) {
                        inEl.style.display = '';
                        inEl.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 300 });
                    });
                }
            });
        });
    }

    function actualizeFilter(): void {
        const seeAllLabel = document.querySelector('.filter-badge');
        if (seeAllLabel) seeAllLabel.innerHTML = String(nb_plugin.all);
        const seeActiveLabel = document.querySelector("label[for='seeActive'] .filter-badge");
        if (seeActiveLabel) seeActiveLabel.innerHTML = String(nb_plugin.active);
        const seeInactiveLabel = document.querySelector("label[for='seeInactive'] .filter-badge");
        if (seeInactiveLabel) seeInactiveLabel.innerHTML = String(nb_plugin.inactive);
        const seeOtherLabel = document.querySelector("label[for='seeOther'] .filter-badge");
        if (seeOtherLabel) seeOtherLabel.innerHTML = String(nb_plugin.other);

        document.querySelectorAll<HTMLElement>('.filterLabel').forEach(el => { el.style.display = ''; });

        if (nb_plugin.active === 0) {
            const lbl = document.querySelector<HTMLElement>("label[for='seeActive']");
            if (lbl) lbl.style.display = 'none';
            if ((document.getElementById('seeActive') as HTMLInputElement | null)?.checked) {
                (document.getElementById('seeAll'))?.click();
            }
        }
        if (nb_plugin.inactive === 0) {
            const lbl = document.querySelector<HTMLElement>("label[for='seeInactive']");
            if (lbl) lbl.style.display = 'none';
            if ((document.getElementById('seeInactive') as HTMLInputElement | null)?.checked) {
                (document.getElementById('seeAll'))?.click();
            }
        }
        if (nb_plugin.other === 0) {
            const lbl = document.querySelector<HTMLElement>("label[for='seeOther']");
            if (lbl) lbl.style.display = 'none';
            if ((document.getElementById('seeOther') as HTMLInputElement | null)?.checked) {
                (document.getElementById('seeAll'))?.click();
            }
        }
    }

    function setDisplayClassic(): void {
        document.querySelectorAll<HTMLElement>('.pluginContainer').forEach(el => { el.classList.remove('line-form', 'compact-form'); el.classList.add('classic-form'); });
        document.querySelectorAll<HTMLElement>('.pluginDesc, .pluginActions').forEach(el => { el.style.display = ''; });
        document.querySelectorAll<HTMLElement>('.pluginActionsSmallIcons').forEach(el => { el.style.display = 'none'; });
        document.querySelectorAll<HTMLElement>('.pluginName').forEach(el => el.classList.remove('pluginNameCompact'));
    }
    function setDisplayCompact(): void {
        document.querySelectorAll<HTMLElement>('.pluginContainer').forEach(el => { el.classList.remove('line-form', 'classic-form'); el.classList.add('compact-form'); });
        document.querySelectorAll<HTMLElement>('.pluginDesc, .pluginActions').forEach(el => { el.style.display = 'none'; });
        document.querySelectorAll<HTMLElement>('.pluginActionsSmallIcons').forEach(el => { el.style.display = ''; });
        document.querySelectorAll<HTMLElement>('.pluginName').forEach(el => el.classList.add('pluginNameCompact'));
    }
    function setDisplayLine(): void {
        document.querySelectorAll<HTMLElement>('.pluginContainer').forEach(el => { el.classList.add('line-form'); el.classList.remove('compact-form', 'classic-form'); });
        document.querySelectorAll<HTMLElement>('.pluginDesc, .pluginActions').forEach(el => { el.style.display = ''; });
        document.querySelectorAll<HTMLElement>('.pluginActionsSmallIcons').forEach(el => { el.style.display = 'none'; });
    }

    function activatePlugin(id: string): void {
        const switchEl = document.querySelector<HTMLButtonElement>('#' + id + ' .switch');
        if (switchEl) switchEl.disabled = true;
        fetch('ws.php?' + new URLSearchParams({ method: 'pwg.plugins.performAction', action: 'activate', plugin: id, pwg_token, format: 'json' }).toString())
            .then(r => r.json())
            .then(function (data: { stat: string }) {
                const sw = document.querySelector<HTMLButtonElement>('#' + id + ' .switch');
                if (sw) sw.disabled = false;
                if (data.stat === 'ok') {
                    const successLabel = document.querySelector<HTMLElement>('#' + id + ' .AddPluginSuccess label span');
                    if (successLabel) successLabel.innerHTML = pluginAddedStr;
                    const successEl = document.querySelector<HTMLElement>('#' + id + ' .AddPluginSuccess');
                    if (successEl) { successEl.style.display = 'flex'; setTimeout(() => { successEl.style.display = 'none'; }, 3000); }
                    nb_plugin.active += 1;
                    nb_plugin.inactive -= 1;
                    actualizeFilter();
                }
            })
            .catch(function (e: unknown) {
                console.log(e);
                const errorEl = document.querySelector<HTMLElement>('#' + id + ' .PluginActionError');
                if (errorEl) { errorEl.style.display = 'flex'; setTimeout(() => { errorEl.style.display = 'none'; }, 4000); }
            });
    }

    function deactivatePlugin(id: string): void {
        const switchEl = document.querySelector<HTMLButtonElement>('#' + id + ' .switch');
        if (switchEl) switchEl.disabled = true;
        fetch('ws.php?' + new URLSearchParams({ method: 'pwg.plugins.performAction', action: 'deactivate', plugin: id, pwg_token, format: 'json' }).toString())
            .then(r => r.json())
            .then(function (data: { stat: string }) {
                const sw = document.querySelector<HTMLButtonElement>('#' + id + ' .switch');
                if (sw) sw.disabled = false;
                if (data.stat === 'ok') {
                    const deactivateLabel = document.querySelector<HTMLElement>('#' + id + ' .DeactivatePluginSuccess label span');
                    if (deactivateLabel) deactivateLabel.innerHTML = pluginDeactivatedStr;
                    const deactivateEl = document.querySelector<HTMLElement>('#' + id + ' .DeactivatePluginSuccess');
                    if (deactivateEl) { deactivateEl.style.display = 'flex'; setTimeout(() => { deactivateEl.style.display = 'none'; }, 3000); }
                    nb_plugin.inactive += 1;
                    nb_plugin.active -= 1;
                    actualizeFilter();
                }
            })
            .catch(function (e: unknown) {
                console.log(e);
                const errorEl = document.querySelector<HTMLElement>('#' + id + ' .PluginActionError');
                if (errorEl) { errorEl.style.display = 'flex'; setTimeout(() => { errorEl.style.display = 'none'; }, 4000); }
            });
    }

    function deletePlugin(id: string): void {
        fetch('ws.php?' + new URLSearchParams({ method: 'pwg.plugins.performAction', action: 'delete', plugin: id, pwg_token, format: 'json' }).toString())
            .then(r => r.json())
            .then(function (data: { stat: string }) {
                if (data.stat === 'ok') {
                    document.getElementById(id)?.remove();
                    nb_plugin.inactive -= 1;
                    nb_plugin.all -= 1;
                    actualizeFilter();
                }
            })
            .catch(function (e: unknown) {
                console.log(e);
                const errorEl = document.querySelector<HTMLElement>('#' + id + ' .PluginActionError');
                if (errorEl) { errorEl.style.display = 'flex'; setTimeout(() => { errorEl.style.display = 'none'; }, 4000); }
            });
    }

    function restorePlugin(id: string): void {
        fetch('ws.php?' + new URLSearchParams({ method: 'pwg.plugins.performAction', action: 'restore', plugin: id, pwg_token, format: 'json' }).toString())
            .then(r => r.json())
            .then(function (data: { stat: string }) {
                if (data.stat === 'ok') {
                    const restoreLabel = document.querySelector<HTMLElement>('#' + id + ' .RestorePluginSuccess label span');
                    if (restoreLabel) restoreLabel.innerHTML = pluginRestoredStr;
                    const restoreEl = document.querySelector<HTMLElement>('#' + id + ' .RestorePluginSuccess');
                    if (restoreEl) { restoreEl.style.display = 'flex'; setTimeout(() => { restoreEl.style.display = 'none'; }, 3000); }
                }
            })
            .catch(function (e: unknown) {
                console.log(e);
                const errorEl = document.querySelector<HTMLElement>('#' + id + ' .PluginActionError');
                if (errorEl) { errorEl.style.display = 'flex'; setTimeout(() => { errorEl.style.display = 'none'; }, 4000); }
            });
    }

    function uninstallPlugin(id: string): void {
        fetch('ws.php?' + new URLSearchParams({ method: 'pwg.plugins.performAction', action: 'uninstall', plugin: id, pwg_token, format: 'json' }).toString())
            .then(r => r.json())
            .then(function () {
                document.getElementById(id)?.remove();
                nb_plugin.other -= 1;
                nb_plugin.all -= 1;
                actualizeFilter();
            })
            .catch(function (e: { message?: string }) {
                const errorEl = document.querySelector<HTMLElement>('#' + id + ' .PluginActionError');
                if (errorEl) { errorEl.style.display = 'flex'; setTimeout(() => { errorEl.style.display = 'none'; }, 4000); }
                console.log(e.message);
            });
    }

    actualizeFilter();

    if ((document.getElementById('displayClassic') as HTMLInputElement | null)?.checked) setDisplayClassic();
    if ((document.getElementById('displayCompact') as HTMLInputElement | null)?.checked) setDisplayCompact();
    if ((document.getElementById('displayLine') as HTMLInputElement | null)?.checked) setDisplayLine();

    document.querySelectorAll<HTMLInputElement>('#displayClassic, #displayCompact, #displayLine').forEach(function (el) {
        el.addEventListener('change', function () {
            if (el.id === 'displayClassic') { setDisplayClassic(); set_view_selector('classic'); }
            else if (el.id === 'displayCompact') { setDisplayCompact(); set_view_selector('compact'); }
            else if (el.id === 'displayLine') { setDisplayLine(); set_view_selector('line'); }
        });
    });

    if (nb_plugin.active > 0) {
        document.querySelectorAll<HTMLElement>('.pluginMiniBox').forEach(el => { if (!el.classList.contains('plugin-active')) el.style.display = 'none'; });
        (document.getElementById('seeActive'))?.click();
    } else {
        document.querySelectorAll<HTMLElement>('.pluginMiniBox').forEach(el => { el.style.display = ''; });
    }

    document.querySelectorAll<HTMLInputElement>('#seeAll, #seeActive, #seeInactive, #seeOther').forEach(function (el) {
        el.addEventListener('change', function () {
            document.querySelectorAll<HTMLElement>('.pluginBox').forEach(box => { box.style.display = ''; });
            if (el.id === 'seeActive') {
                document.querySelectorAll<HTMLElement>('.pluginBox').forEach(box => { if (!box.classList.contains('plugin-active')) box.style.display = 'none'; });
            } else if (el.id === 'seeInactive') {
                document.querySelectorAll<HTMLElement>('.pluginBox').forEach(box => { if (!box.classList.contains('plugin-inactive')) box.style.display = 'none'; });
            } else if (el.id === 'seeOther') {
                document.querySelectorAll<HTMLElement>('.pluginBox').forEach(box => { if (box.classList.contains('plugin-active') || box.classList.contains('plugin-inactive')) box.style.display = 'none'; });
            }
            document.querySelector<HTMLElement>('.search-input')?.dispatchEvent(new Event('input'));
        });
    });

    if (isWebmaster !== 0) {
        document.querySelectorAll<HTMLInputElement>('.switch').forEach(function (switchEl) {
            switchEl.addEventListener('change', function () {
                document.querySelectorAll<HTMLElement>('.pluginMiniBox').forEach(el => el.classList.add('usable'));
                let pluginBox = switchEl.closest<HTMLElement>('.pluginBox') ?? switchEl.closest<HTMLElement>('.pluginMiniBox');
                if (!pluginBox) return;

                const toggleInput = switchEl.querySelector<HTMLInputElement>('#toggleSelectionMode');
                if (toggleInput?.checked) {
                    activatePlugin(pluginBox.id);
                    pluginBox.classList.add('plugin-active');
                    pluginBox.classList.remove('plugin-inactive');
                    const unavail = pluginBox.querySelector<HTMLElement>('.pluginUnavailableAction');
                    if (unavail?.getAttribute('href')) { unavail.classList.remove('pluginUnavailableAction'); unavail.classList.add('pluginActionLevel1'); }
                } else {
                    deactivatePlugin(pluginBox.id);
                    pluginBox.classList.remove('plugin-active');
                    pluginBox.classList.add('plugin-inactive');
                    const level1 = pluginBox.querySelector<HTMLElement>('.pluginActionLevel1');
                    if (level1) { level1.classList.remove('pluginActionLevel1'); level1.classList.add('pluginUnavailableAction'); }
                }
                actualizeFilter();
            });
        });
    } else {
        document.querySelectorAll<HTMLElement>('.pluginMiniBox').forEach(el => el.classList.add('notUsable'));
        document.querySelectorAll<HTMLElement>('.plugin-active .slider').forEach(el => el.classList.add('deactivate_disabled'));
        document.querySelectorAll<HTMLElement>('.plugin-inactive .slider').forEach(el => el.classList.add('activate_disabled'));
        document.querySelectorAll<HTMLInputElement>('.switch input').forEach(function (el) {
            el.addEventListener('click', function (event) {
                el.classList.add('disabled');
                event.preventDefault();
                event.stopPropagation();
                const id = el.closest<HTMLElement>('.pluginBox')?.id ?? el.closest<HTMLElement>('.pluginMiniBox')?.id ?? '';
                const errorLabel = document.querySelector<HTMLElement>('#' + id + ' .PluginActionError label span');
                if (errorLabel) errorLabel.innerHTML = notWebmaster;
                const errorEl = document.querySelector<HTMLElement>('#' + id + ' .PluginActionError');
                if (errorEl) { errorEl.style.display = 'flex'; setTimeout(() => { errorEl.style.display = 'none'; }, 4000); }
                setTimeout(() => { document.querySelectorAll<HTMLInputElement>('.switch input').forEach(inp => inp.classList.remove('disabled')); }, 400);
            });
        });
    }

    document.querySelectorAll<HTMLElement>('.pluginContent .dropdown-option.delete-plugin-button').forEach(function (el) {
        el.addEventListener('click', function () {
            const plugin_name = el.closest<HTMLElement>('.pluginContent')?.querySelector<HTMLElement>('.pluginName')?.innerHTML.trim() ?? '';
            const plugin_id = el.closest<HTMLElement>('.pluginBox')?.id ?? '';
            pwgConfirm({ title: deletePluginMsg.replace('%s', plugin_name), buttons: { confirm: { text: confirmMsg, btnClass: 'btn-red', action: () => deletePlugin(plugin_id) }, cancel: { text: cancelMsg } } });
        });
    });

    document.querySelectorAll<HTMLElement>('.pluginContent .dropdown-option.plugin-restore').forEach(function (el) {
        el.addEventListener('click', function () {
            const plugin_name = el.closest<HTMLElement>('.pluginContent')?.querySelector<HTMLElement>('.pluginName')?.innerHTML.trim() ?? '';
            const plugin_id = el.closest<HTMLElement>('.pluginBox')?.id ?? '';
            pwgConfirm({ title: restorePluginMsg.replace('%s', plugin_name), content: strRestoreDef, buttons: { confirm: { text: confirmMsg, btnClass: 'btn-red', action: () => restorePlugin(plugin_id) }, cancel: { text: cancelMsg } } });
        });
    });

    document.querySelectorAll<HTMLElement>('.pluginContent .uninstall-plugin-button').forEach(function (el) {
        el.addEventListener('click', function () {
            const plugin_name = el.closest<HTMLElement>('.pluginContent')?.querySelector<HTMLElement>('.pluginName')?.innerHTML.trim() ?? '';
            const plugin_id = el.closest<HTMLElement>('.pluginBox')?.id ?? '';
            pwgConfirm({ title: uninstallPluginMsg.replace('%s', plugin_name), buttons: { confirm: { text: confirmMsg, btnClass: 'btn-red', action: () => uninstallPlugin(plugin_id) }, cancel: { text: cancelMsg } } });
        });
    });

    document.querySelectorAll<HTMLButtonElement>('.showInactivePlugins button').forEach(el => el.addEventListener('click', showInactivePlugins));

    if (pluginFilter === 'deactivated') {
        document.querySelector<HTMLElement>(".filterLabel[for='seeInactive']")?.click();
    }

    document.addEventListener('keydown', function (e) {
        if (e.keyCode === 58) {
            document.querySelector<HTMLInputElement>('.pluginFilter input.search-input')?.focus();
            return false;
        }
    });

    document.querySelectorAll<HTMLInputElement>('.pluginFilter input').forEach(function (inputEl) {
        inputEl.addEventListener('input', function () {
            const text = inputEl.value.toLowerCase();
            let searchNumber = 0, searchActive = 0, searchInactive = 0, searchOther = 0;

            document.querySelectorAll<HTMLElement>('.pluginBox').forEach(function (box) {
                if (text === '') {
                    document.querySelectorAll<HTMLElement>('.nbPluginsSearch').forEach(el => { el.style.display = 'none'; });
                    const seeAll = (document.getElementById('seeAll') as HTMLInputElement | null)?.checked;
                    const seeActive = (document.getElementById('seeActive') as HTMLInputElement | null)?.checked;
                    const seeInactive = (document.getElementById('seeInactive') as HTMLInputElement | null)?.checked;
                    const seeOther = (document.getElementById('seeOther') as HTMLInputElement | null)?.checked;
                    if (seeAll) box.style.display = '';
                    if (seeActive && box.classList.contains('plugin-active')) box.style.display = '';
                    if (seeInactive && box.classList.contains('plugin-inactive')) box.style.display = '';
                    if (seeOther && (box.classList.contains('plugin-merged') || box.classList.contains('plugin-missing'))) box.style.display = '';
                    if (box.classList.contains('plugin-active')) searchActive++;
                    if (box.classList.contains('plugin-inactive')) searchInactive++;
                    if (box.classList.contains('plugin-merged') || box.classList.contains('plugin-missing')) searchOther++;
                    searchNumber++;
                } else {
                    const name = box.querySelector<HTMLElement>('.pluginName')?.textContent?.toLowerCase() ?? '';
                    const description = box.querySelector<HTMLElement>('.pluginDesc')?.textContent?.toLowerCase() ?? '';
                    document.querySelectorAll<HTMLElement>('.nbPluginsSearch').forEach(el => { el.style.display = ''; });
                    if (name.includes(text) || description.includes(text)) {
                        searchNumber++;
                        const seeAll = (document.getElementById('seeAll') as HTMLInputElement | null)?.checked;
                        const seeActive = (document.getElementById('seeActive') as HTMLInputElement | null)?.checked;
                        const seeInactive = (document.getElementById('seeInactive') as HTMLInputElement | null)?.checked;
                        const seeOther = (document.getElementById('seeOther') as HTMLInputElement | null)?.checked;
                        if (seeAll) box.style.display = '';
                        if (seeActive && box.classList.contains('plugin-active')) box.style.display = '';
                        if (seeInactive && box.classList.contains('plugin-inactive')) box.style.display = '';
                        if (seeOther && (box.classList.contains('plugin-merged') || box.classList.contains('plugin-missing'))) box.style.display = '';
                        if (box.classList.contains('plugin-active')) searchActive++;
                        if (box.classList.contains('plugin-inactive')) searchInactive++;
                        if (box.classList.contains('plugin-merged') || box.classList.contains('plugin-missing')) searchOther++;
                    } else {
                        box.style.display = 'none';
                    }
                }
                nb_plugin.all = searchNumber;
                nb_plugin.active = searchActive;
                nb_plugin.inactive = searchInactive;
                nb_plugin.other = searchOther;
            });

            actualizeFilter();

            const nbSearchEl = document.querySelector<HTMLElement>('.nbPluginsSearch');
            if (searchNumber === 0) { if (nbSearchEl) nbSearchEl.innerHTML = nothingFound; }
            else if (searchNumber === 1) { if (nbSearchEl) nbSearchEl.innerHTML = pluginFound.replace('%s', String(searchNumber)); }
            else { if (nbSearchEl) nbSearchEl.innerHTML = xPluginsFound.replace('%s', String(searchNumber)); }
        });
    });

    function set_view_selector(view_type: string): void {
        void fetch('ws.php?format=json&method=pwg.users.preferences.set', {
            method: 'POST',
            body: new URLSearchParams({ param: 'plugin-manager-view', value: view_type }),
        });
    }

    function performPluginDeactivate(id: string): void {
        _deactivateTotal++;
        _deactivateQueue = _deactivateQueue.then(function () {
            return fetch('ws.php?' + new URLSearchParams({ method: 'pwg.plugins.performAction', action: 'deactivate', plugin: id, pwg_token, format: 'json' }).toString())
                .then(r => r.json())
                .then(function (data: { stat: string }) {
                    if (data.stat === 'ok') {
                        const el = document.getElementById(id);
                        if (el) { el.classList.remove('active'); el.classList.add('inactive'); }
                    }
                    _deactivateDone++;
                    if (_deactivateDone === _deactivateTotal) location.reload();
                });
        });
    }

    actualizeFilter();

    document.querySelectorAll<HTMLElement>('.pluginBox').forEach(function (el) {
        const showOptions = el.querySelector<HTMLElement>('.showOptions');
        if (showOptions) {
            showOptions.addEventListener('click', function () {
                const optionsBlock = el.querySelector<HTMLElement>('.PluginOptionsBlock');
                if (optionsBlock) optionsBlock.style.display = optionsBlock.style.display === 'none' ? 'block' : 'none';
            });
        }
    });

    document.querySelectorAll<HTMLAnchorElement>('div.deactivate_all a').forEach(function (el) {
        el.addEventListener('click', function () {
            pwgConfirm({
                title: deactivateAllMsg,
                buttons: {
                    confirm: {
                        text: confirmMsg, btnClass: 'btn-red',
                        action: function () {
                            _deactivateTotal = 0;
                            _deactivateDone = 0;
                            _deactivateQueue = Promise.resolve();
                            document.querySelectorAll<HTMLElement>('div.active').forEach(el => performPluginDeactivate(el.id));
                        },
                    },
                    cancel: { text: cancelMsg },
                },
            });
        });
    });

    void fetch('admin.php?' + new URLSearchParams({ page: 'plugins_installed', incompatible_plugins: 'true' }).toString())
        .then(r => r.json())
        .then(function (data: string[]) {
            for (let i = 0; i < data.length; i++) {
                if (showDetails) {
                    document.querySelector<HTMLElement>('#' + data[i] + ' .pluginName')?.insertAdjacentHTML('afterbegin', '<a class="warning" title="' + incompatibleMsg + '"></a>');
                } else {
                    document.querySelector<HTMLElement>('#' + data[i] + ' .pluginName')?.insertAdjacentHTML('afterbegin', '<span class="warning" title="' + incompatibleMsg + '"></span>');
                }
                const el = document.getElementById(data[i]);
                if (el) {
                    el.classList.add('incompatible');
                    el.querySelectorAll<HTMLAnchorElement>('.activate').forEach(function (activateBtn) {
                        pwgConfirmFollowHref(activateBtn, { alert_title: incompatibleMsg + activateMsg, alert_confirm: confirmMsg, alert_cancel: cancelMsg });
                    });
                }
            }
            tippy('.warning', { delay: 0, maxWidth: 250, placement: 'top' });
        });

    tippy('.fullInfo', { delay: 500, maxWidth: 300, placement: 'top' });

    document.addEventListener('mouseup', function (e) {
        e.stopPropagation();
        document.querySelectorAll<HTMLElement>('.pluginBox').forEach(function (el) {
            const showOptions = el.querySelector<HTMLElement>('.showOptions');
            if (showOptions && !showOptions.contains(e.target as Node)) {
                const optionsBlock = el.querySelector<HTMLElement>('.PluginOptionsBlock');
                if (optionsBlock) optionsBlock.style.display = 'none';
            }
        });
    });
}

initModule(init as (cfg: Record<string, unknown>) => void);
