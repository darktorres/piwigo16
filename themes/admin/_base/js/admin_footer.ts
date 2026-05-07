import { config } from './config';

// Externally-targeted links open in a new tab/window.
document.querySelectorAll<HTMLAnchorElement>('a.externalLink').forEach((el) => {
    el.addEventListener('click', (e) => {
        e.preventDefault();
        window.open(el.getAttribute('href') || '');
    });
});

// "What's new" dialog wiring: shown on first visit of a major release
// (driven by the data-auto-show attribute) and via the bell icon; dismissed
// by any element with class .close_whats_new.
const whatsNew = document.getElementById('whats_new');

function showWhatsNew(): void {
    if (whatsNew) {
        whatsNew.style.display = '';
    }
}

function hideWhatsNew(): void {
    if (!whatsNew) return;
    const version = whatsNew.dataset['whatsNewVersion'] ?? '';
    if (version) {
        fetch(config.wsUrl + 'format=json&method=pwg.users.preferences.set', {
            method: 'POST',
            body: new URLSearchParams({
                param: 'show_whats_new_' + version,
                value: 'false',
            }),
        }).catch(() => {
            /* best-effort */
        });
    }
    whatsNew.style.display = 'none';
}

document.getElementById('whats_new_notification')?.addEventListener('click', showWhatsNew);
document.querySelectorAll<HTMLElement>('.close_whats_new').forEach((el) => {
    el.addEventListener('click', hideWhatsNew);
});

if (whatsNew?.dataset['autoShow'] === '1') {
    showWhatsNew();
}
