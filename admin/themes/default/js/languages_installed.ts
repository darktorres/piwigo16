import { initModule } from './moduleInit.js';
import { pwgConfirmFollowHref } from './pwgConfirm.js';

interface LangsInstalledConfig {
    strAreYouSure?: string;
    strYesImSure?: string;
    strIChangedMyMind?: string;
}

export function init(cfg: LangsInstalledConfig): void {
    document.querySelectorAll<HTMLAnchorElement>(".delete-lang-button").forEach(function (el) {
        const langBox = el.closest<HTMLElement>(".languageBox");
        const langName = langBox?.querySelector('.languageName')?.innerHTML ?? '';
        pwgConfirmFollowHref(el, {
            alert_title: (cfg.strAreYouSure ?? 'Are you sure?').replace("%s", langName),
            alert_confirm: cfg.strYesImSure ?? 'Yes',
            alert_cancel: cfg.strIChangedMyMind ?? 'No',
        });
    });
}

initModule(init as (cfg: Record<string, unknown>) => void);
