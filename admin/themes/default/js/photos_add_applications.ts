import GLightbox from 'glightbox';

const _docReady = (fn: () => void): void => {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
};

_docReady(function () {
    GLightbox({ selector: '.illustration a' });
});
