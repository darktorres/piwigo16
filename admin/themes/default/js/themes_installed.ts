import { initModule } from './moduleInit.js';
import { pwgConfirmFollowHref } from './pwgConfirm.js';
import GLightbox from 'glightbox';

interface ThemesInstalledConfig {
    strDeleteTheme?: string;
    strYesImSure?: string;
    strIChangedMyMind?: string;
    strDeleteThemeMsg?: string;
}

export function init(cfg: ThemesInstalledConfig): void {
    const title_msg = cfg.strDeleteTheme ?? 'Are you sure?';
    const confirm_msg = cfg.strYesImSure ?? 'Yes';
    const cancel_msg = cfg.strIChangedMyMind ?? 'No';

    document.querySelectorAll<HTMLAnchorElement>(".delete-theme-button").forEach(function (el) {
        const theme_name = el.closest(".themeBox")?.querySelector<HTMLElement>(".themeName")?.getAttribute("title") ?? '';
        const title = (cfg.strDeleteThemeMsg ?? 'Are you sure you want to delete the theme "%s"?').replace("%s", theme_name);
        pwgConfirmFollowHref(el, { alert_title: title, alert_confirm: confirm_msg, alert_cancel: cancel_msg });
    });

    void title_msg;

    GLightbox({ selector: 'a.preview-box' });

    document.addEventListener('mouseup', function (e) {
        e.stopPropagation();
        if (!(e.target as Element).classList.contains('showInfo')) {
            document.querySelectorAll<HTMLElement>('.showInfo-dropdown').forEach(el => { el.style.display = 'none'; });
        }
    });

    window.addEventListener('load', function () {
        document.querySelectorAll<HTMLElement>('.themeBox').forEach(function (box) {
            box.querySelector<HTMLElement>('.showInfo')?.addEventListener('click', function () {
                document.querySelectorAll<HTMLElement>('.showInfo-dropdown').forEach(el => { el.style.display = 'none'; });
                const dropdown = box.querySelector<HTMLElement>('.showInfo-dropdown');
                if (dropdown) dropdown.style.display = '';
            });

            const screenImage = box.querySelector<HTMLImageElement>(".preview-box img");
            const previewBox = box.querySelector<HTMLElement>(".preview-box");
            if (screenImage && previewBox) {
                const imageW = screenImage.clientWidth;
                const imageH = screenImage.clientHeight;
                const size = previewBox.clientWidth;
                if (imageW > imageH) {
                    screenImage.style.height = size + 'px';
                    screenImage.style.width = (imageW * size / imageH) + 'px';
                } else {
                    screenImage.style.width = size + 'px';
                    screenImage.style.height = (imageH * size / imageW) + 'px';
                }
            }
        });
    });
}

initModule(init as (cfg: Record<string, unknown>) => void);
