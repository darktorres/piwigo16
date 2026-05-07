document.querySelectorAll<HTMLElement>('[data-close-window]').forEach((el) => {
    el.addEventListener('click', (e) => {
        e.preventDefault();
        window.close();
    });
});
