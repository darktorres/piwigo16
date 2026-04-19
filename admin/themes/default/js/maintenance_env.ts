import { initModule } from './moduleInit.js';

interface MaintenanceEnvConfig {
    no_active_plugin?: string;
    error_occurred?: string;
}

interface PluginItem { state: string; name: string }

export function init(cfg: MaintenanceEnvConfig): void {
    const { no_active_plugin, error_occurred } = cfg;

    fetch("ws.php?format=json&method=pwg.plugins.getList")
        .then(r => r.json())
        .then(function (data: { result: PluginItem[] }) {
            const plugins = data.result;
            let nbActivatedPlugins = 0;
            plugins.forEach(function (plugin) {
                if (plugin.state === "active") {
                    const pluginList = document.querySelector("#pluginList ul");
                    if (pluginList) {
                        pluginList.insertAdjacentHTML("beforeend", "<li>" + plugin.name + "</li>");
                        const spinner = pluginList.querySelector<HTMLElement>("i");
                        if (spinner) spinner.style.display = 'none';
                    }
                    nbActivatedPlugins++;
                }
            });
            if (nbActivatedPlugins === 0) {
                const pluginList = document.querySelector("#pluginList ul");
                if (pluginList) {
                    const spinner = pluginList.querySelector<HTMLElement>("i");
                    if (spinner) spinner.style.display = 'none';
                    pluginList.insertAdjacentHTML("beforeend", "<p>" + (no_active_plugin ?? 'No active plugins') + "</p>");
                }
            }
            document.querySelectorAll(".badge-number").forEach(el => { el.textContent = (el.textContent || '') + nbActivatedPlugins; });
        })
        .catch(function () {
            document.querySelectorAll(".badge-number").forEach(el => { el.textContent = (el.textContent || '') + 0; });
            const pluginList = document.querySelector("#pluginList ul");
            if (pluginList) {
                pluginList.insertAdjacentHTML("beforeend", "<p>" + (error_occurred ?? 'An error occurred') + "</p>");
                const spinner = pluginList.querySelector<HTMLElement>("i");
                if (spinner) spinner.style.display = 'none';
            }
        });
}

initModule(init as (cfg: Record<string, unknown>) => void);
