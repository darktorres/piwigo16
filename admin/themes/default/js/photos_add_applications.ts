import GLightbox from 'glightbox';

const _docReady = (fn: () => void): void => {
    document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn);
};

_docReady(function () {
    GLightbox({ selector: '.illustration a' });
});
