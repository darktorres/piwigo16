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

const langSelect = document.querySelector<HTMLSelectElement>('select[data-install-language]');
if (langSelect) {
  langSelect.addEventListener('change', () => {
    document.location.href = 'install.php?language=' + langSelect.value;
  });
}

document.querySelectorAll<HTMLElement>('[data-install-download-config]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const url = btn.getAttribute('data-install-download-config') || '';
    if (url) {
      window.open(url);
    }
  });
});
