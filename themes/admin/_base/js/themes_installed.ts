import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.css';
import { getPageData } from './page-data';

interface ThemesInstalledPageData {
    str_delete_theme_confirm: string;
}

const { str_delete_theme_confirm } = getPageData<ThemesInstalledPageData>();

document.querySelectorAll<HTMLAnchorElement>('.delete-theme-button').forEach((btn) => {
    const themeBox = btn.closest('.themeBox');
    const theme_name = themeBox?.querySelector('.themeName')?.getAttribute('title') ?? '';
    const alert_title = str_delete_theme_confirm.replace('%s', theme_name);
    btn.addEventListener('click', (e) => {
        if (!window.confirm(alert_title)) {
            e.preventDefault();
        }
    });
});

GLightbox({ selector: 'a.preview-box' });

document.addEventListener('mouseup', (e) => {
    e.stopPropagation();
    const target = e.target as HTMLElement | null;
    if (target?.classList.contains('showInfo') !== true) {
        document.querySelectorAll<HTMLElement>('.showInfo-dropdown').forEach((el) => {
            el.style.display = 'none';
        });
    }
});

document.querySelectorAll<HTMLElement>('.themeBox').forEach((box) => {
    const showInfoBtn = box.querySelector<HTMLElement>('.showInfo');
    if (showInfoBtn) {
        showInfoBtn.addEventListener('click', () => {
            const dropdown = box.querySelector<HTMLElement>('.showInfo-dropdown');
            document.querySelectorAll<HTMLElement>('.showInfo-dropdown').forEach((el) => {
                if (el !== dropdown) {
                    el.style.display = 'none';
                }
            });
            if (dropdown) {
                dropdown.style.display =
                    dropdown.style.display === 'none' || dropdown.style.display === ''
                        ? 'block'
                        : 'none';
            }
        });
    }

    const screenImage = box.querySelector<HTMLImageElement>('.preview-box img');
    const previewBox = box.querySelector<HTMLElement>('.preview-box');
    if (screenImage && previewBox) {
        const imageW = screenImage.offsetWidth;
        const imageH = screenImage.offsetHeight;
        const size = previewBox.offsetWidth;

        if (imageW > imageH) {
            screenImage.style.height = size + 'px';
            screenImage.style.width = (imageW * size) / imageH + 'px';
        } else {
            screenImage.style.width = size + 'px';
            screenImage.style.height = (imageH * size) / imageW + 'px';
        }
    }
});
