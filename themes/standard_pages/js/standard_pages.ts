import { getPageData } from './page-data';

interface StandardPagesData {
    selected_language: string;
    url_logo_light: string;
    url_logo_dark: string;
}

const { selected_language, url_logo_light, url_logo_dark } =
    getPageData<StandardPagesData>('pwg-std-pages-data');

let modeCookie = getCookie('mode');
if (modeCookie !== '') {
    toggle_mode(modeCookie);
} else {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    toggle_mode(prefersDark ? 'dark' : 'light');
}

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
    toggle_mode(event.matches ? 'dark' : 'light');
});

document.addEventListener('DOMContentLoaded', () => {
    const langEl = document.getElementById('selected-language');
    if (langEl) langEl.textContent = selected_language;

    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (e) => {
            let isValid = true;
            form.querySelectorAll<HTMLElement>('.column-flex').forEach((col) => {
                const input = col.querySelector<HTMLInputElement>('input');
                if (input?.dataset['required'] === 'true') {
                    const errorMessage = col.querySelector<HTMLElement>('.error-message');
                    if (!String(input.value ?? '').trim()) {
                        e.preventDefault();
                        input.setCustomValidity('');
                        if (errorMessage) errorMessage.style.display = '';
                        isValid = false;
                    } else {
                        input.setCustomValidity('');
                        if (errorMessage) errorMessage.style.display = 'none';
                    }
                }
            });
            return isValid;
        });
    });

    document.querySelectorAll<HTMLInputElement>('.column-flex input').forEach((input) => {
        input.addEventListener('input', () => {
            const errorMessage = input
                .closest<HTMLElement>('.column-flex')
                ?.querySelector<HTMLElement>('.error-message');
            input.setCustomValidity('');
            if (errorMessage) errorMessage.style.display = 'none';
        });
    });

    document.querySelectorAll<HTMLElement>('.togglePassword').forEach((toggle) => {
        toggle.addEventListener('click', (e) => {
            const target = e.target as HTMLElement;
            const input = target.parentElement?.querySelector<HTMLInputElement>('input');
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                target.style.color = '#ff7700';
            } else {
                input.type = 'password';
                target.style.color = '#898989';
            }
        });
    });

    document.querySelectorAll<HTMLAnchorElement>('#other-languages a').forEach((a) => {
        a.addEventListener('click', () => {
            const href = a.getAttribute('href');
            if (!href) return;
            const clickedUrl = new URL(href, location.href);
            const selectedLang = clickedUrl.searchParams.get('lang');
            if (selectedLang) setCookie('lang', selectedLang, 1);
        });
    });

    document.querySelectorAll<HTMLElement>('[data-toggle-mode]').forEach((el) => {
        el.addEventListener('click', () => {
            const mode = el.getAttribute('data-toggle-mode') || '';
            if (mode === 'dark' || mode === 'light') toggle_mode(mode);
        });
    });

    document.querySelectorAll<HTMLElement>('[data-set-lang]').forEach((el) => {
        el.addEventListener('click', () => {
            const code = el.getAttribute('data-set-lang') || '';
            if (code) setCookie('lang', code, 30);
        });
    });
});

function toggle_mode(mode: string): void {
    setCookie('mode', mode, 30);
    const logoEl = document.getElementById('piwigo-logo') as HTMLImageElement | null;
    const modeEl = document.getElementById('mode');
    const lightBtn = document.getElementById('toggle_mode_light');
    const darkBtn = document.getElementById('toggle_mode_dark');
    if (mode === 'dark') {
        if (lightBtn) lightBtn.style.display = 'none';
        if (darkBtn) darkBtn.style.display = '';
        modeEl?.classList.add('dark');
        modeEl?.classList.remove('light');
        if (logoEl) logoEl.src = url_logo_dark;
    } else {
        if (darkBtn) darkBtn.style.display = 'none';
        if (lightBtn) lightBtn.style.display = '';
        modeEl?.classList.add('light');
        modeEl?.classList.remove('dark');
        if (logoEl) logoEl.src = url_logo_light;
    }
}

function setCookie(cname: string, cvalue: string, exdays: number): void {
    const d = new Date();
    d.setTime(d.getTime() + exdays * 24 * 60 * 60 * 1000);
    document.cookie = cname + '=' + cvalue + ';expires=' + d.toUTCString() + ';path=/';
    if (cname === 'lang') location.reload();
}

function getCookie(cname: string): string {
    const name = cname + '=';
    const decodedCookie = decodeURIComponent(document.cookie);
    for (let c of decodedCookie.split(';')) {
        while (c.charAt(0) === ' ') c = c.substring(1);
        if (c.indexOf(name) === 0) return c.substring(name.length, c.length);
    }
    return '';
}

export {};
