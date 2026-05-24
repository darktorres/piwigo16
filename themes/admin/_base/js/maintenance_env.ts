import '../css/pages/maintenance-env.css';
import { getPageData } from './page-data';
import { config } from './config';

interface MaintenanceEnvPageData {
    no_active_plugin: string;
    error_occured: string;
}

const { no_active_plugin, error_occured } = getPageData<MaintenanceEnvPageData>();

let plugins: Array<{ name: string; state: string }> = [];
let nbActivatedPlugins = 0;

document.addEventListener('DOMContentLoaded', () => {
    fetch(config.wsUrl + 'format=json&method=pwg.plugins.getList')
        .then((r) => r.json())
        .then((data: { result: Array<{ name: string; state: string }> }) => {
            plugins = data.result;
            nbActivatedPlugins = 0;
            const pluginList = document.querySelector<HTMLUListElement>('#pluginList ul')!;
            plugins.forEach((plugin) => {
                if (plugin.state === 'active') {
                    pluginList.insertAdjacentHTML('beforeend', '<li>' + plugin.name + '</li>');
                    pluginList.querySelectorAll('i').forEach((i) => {
                        i.style.display = 'none';
                    });
                    nbActivatedPlugins++;
                }
            });
            if (nbActivatedPlugins === 0) {
                pluginList.querySelectorAll('i').forEach((i) => {
                    i.style.display = 'none';
                });
                pluginList.insertAdjacentHTML('beforeend', '<p>' + no_active_plugin + '</p>');
            }
            document.querySelectorAll('.badge-number').forEach((el) => {
                el.insertAdjacentText('beforeend', String(nbActivatedPlugins));
            });
        })
        .catch(() => {
            document.querySelectorAll('.badge-number').forEach((el) => {
                el.insertAdjacentText('beforeend', '0');
            });
            const pluginList = document.querySelector<HTMLUListElement>('#pluginList ul');
            if (pluginList) {
                pluginList.insertAdjacentHTML('beforeend', '<p>' + error_occured + '</p>');
                pluginList.querySelectorAll('i').forEach((i) => {
                    i.style.display = 'none';
                });
            }
        });
});

export {};
