document.querySelectorAll<HTMLAnchorElement>('a.externalLink').forEach((el) => {
    el.addEventListener('click', (e) => {
        e.preventDefault();
        window.open(el.getAttribute('href') || '');
    });
});

const adminMail = document.getElementById('admin_mail') as HTMLInputElement | null;
if (adminMail) {
    adminMail.addEventListener('keyup', () => {
        document.querySelectorAll<HTMLElement>('.adminEmail').forEach((el) => {
            el.textContent = adminMail.value;
        });
    });
}

document
    .querySelectorAll<HTMLSelectElement>('select[data-language-select-redirect]')
    .forEach((sel) => {
        const target = sel.getAttribute('data-language-select-redirect') || '';
        if (!target) return;
        sel.addEventListener('change', () => {
            document.location.href = target + '?language=' + sel.value;
        });
    });

document.querySelectorAll<HTMLElement>('[data-install-download-config]').forEach((btn) => {
    btn.addEventListener('click', () => {
        const url = btn.getAttribute('data-install-download-config') || '';
        if (url) {
            window.open(url);
        }
    });
});
