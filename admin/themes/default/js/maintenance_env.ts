declare var plugins: Array<{ name: string; state: string }>;
declare var hasActivePlugins: boolean;
declare var nbActivatedPlugins: number;
declare var no_active_plugin: string;
declare var error_occured: string;

document.addEventListener('DOMContentLoaded', () => {
    fetch('ws.php?format=json&method=pwg.plugins.getList')
        .then(r => r.json())
        .then((data: { result: Array<{ name: string; state: string }> }) => {
            plugins = data.result;
            hasActivePlugins = false;
            nbActivatedPlugins = 0;
            const pluginList = document.querySelector<HTMLUListElement>('#pluginList ul')!;
            plugins.forEach((plugin) => {
                if (plugin.state === 'active') {
                    hasActivePlugins = true;
                    pluginList.insertAdjacentHTML('beforeend', '<li>' + plugin.name + '</li>');
                    pluginList.querySelectorAll('i').forEach(i => { i.style.display = 'none'; });
                    nbActivatedPlugins++;
                }
            });
            if (!hasActivePlugins) {
                pluginList.querySelectorAll('i').forEach(i => { i.style.display = 'none'; });
                pluginList.insertAdjacentHTML('beforeend', '<p>' + no_active_plugin + '</p>');
            }
            document.querySelectorAll('.badge-number').forEach(el => {
                el.insertAdjacentText('beforeend', String(nbActivatedPlugins));
            });
        })
        .catch(() => {
            document.querySelectorAll('.badge-number').forEach(el => {
                el.insertAdjacentText('beforeend', '0');
            });
            const pluginList = document.querySelector<HTMLUListElement>('#pluginList ul');
            if (pluginList) {
                pluginList.insertAdjacentHTML('beforeend', '<p>' + error_occured + '</p>');
                pluginList.querySelectorAll('i').forEach(i => { i.style.display = 'none'; });
            }
        });
});

export {};
