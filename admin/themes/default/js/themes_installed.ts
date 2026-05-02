import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.css';

GLightbox({ selector: 'a.preview-box' });

document.addEventListener('mouseup', (e) => {
    e.stopPropagation();
    const target = e.target as HTMLElement | null;
    if (!target?.classList.contains('showInfo')) {
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
            screenImage.style.width = (imageW * size / imageH) + 'px';
        } else {
            screenImage.style.width = size + 'px';
            screenImage.style.height = (imageH * size / imageW) + 'px';
        }
    }
});
