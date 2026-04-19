import { initModule } from './moduleInit.js';
import { pwgConfirmFollowHref } from './pwgConfirm.js';

interface SiteManagerConfig {
    strConfirmDeleteSite?: string;
    strYesSure?: string;
    strNoChangedMind?: string;
}

export function init(cfg: SiteManagerConfig): void {
    const showCreateSite = document.getElementById("showCreateSite");
    const createSite = document.getElementById("createSite");
    const openLink = showCreateSite?.querySelector<HTMLAnchorElement>("a");

    openLink?.addEventListener('click', function (e) {
        e.preventDefault();
        if (showCreateSite) showCreateSite.style.display = 'none';
        if (createSite) createSite.style.display = '';
    });

    const title_msg = cfg.strConfirmDeleteSite ?? 'Are you sure you want to delete this site?';
    const confirm_msg = cfg.strYesSure ?? 'Yes, I am sure';
    const cancel_msg = cfg.strNoChangedMind ?? 'No, I have changed my mind';

    document.querySelectorAll<HTMLAnchorElement>(".delete-site-button").forEach(el =>
        { pwgConfirmFollowHref(el, { alert_title: title_msg, alert_confirm: confirm_msg, alert_cancel: cancel_msg }); });
}

initModule(init as (cfg: Record<string, unknown>) => void);
