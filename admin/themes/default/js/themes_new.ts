window.addEventListener('load', () => {
    document.querySelectorAll<HTMLElement>('.themeBox').forEach((box) => {
        const screenImage = box.querySelector<HTMLImageElement>('.preview-box img');
        const previewBox = box.querySelector<HTMLElement>('.preview-box');
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
