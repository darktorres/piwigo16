import { initModule } from './moduleInit.js';

interface PermalinksConfig { nbCats?: number }

export function init(cfg: PermalinksConfig): void {
    const h1 = document.querySelector("h1");
    if (h1) h1.insertAdjacentHTML('beforeend', "<span class='badge-number'>" + (cfg.nbCats ?? 0) + "</span>");

    const addPermalink = document.getElementById("addPermalink");
    const showAddPermalink = document.getElementById("showAddPermalink");

    document.getElementById("addPermalinkOpen")?.addEventListener('click', function (e) {
        e.preventDefault();
        if (addPermalink) addPermalink.style.display = '';
        if (showAddPermalink) showAddPermalink.style.display = 'none';
    });

    document.getElementById("addPermalinkClose")?.addEventListener('click', function (e) {
        e.preventDefault();
        if (addPermalink) addPermalink.style.display = 'none';
        if (showAddPermalink) showAddPermalink.style.display = '';
    });
}

initModule(init as (cfg: Record<string, unknown>) => void);
