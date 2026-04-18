import { initModule } from './moduleInit.js';
import GLightbox from 'glightbox';

export function init(_cfg: Record<string, unknown>): void {
    GLightbox({ selector: 'a.preview-box' });

    window.addEventListener('load', function () {
        document.querySelectorAll<HTMLElement>('.themeBox').forEach(function (box) {
            const screenImage = box.querySelector<HTMLImageElement>(".preview-box img");
            const previewBox = box.querySelector<HTMLElement>(".preview-box");
            if (!screenImage || !previewBox) return;

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
        });
    });
}

initModule(init);
