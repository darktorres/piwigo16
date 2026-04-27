let modeCookie = getCookie('mode');
if (modeCookie !== '') {
    toggle_mode(modeCookie);
} else {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    toggle_mode(prefersDark ? 'dark' : 'light');
}

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
    const newMode = event.matches ? 'dark' : 'light';
    toggle_mode(newMode);
});

jQuery(document).ready(function () {
    const langEl = jQuery('#selected-language');
    if (langEl.length) langEl.text(selected_language);

    jQuery('form').on('submit', function (e) {
        let isValid = true;

        jQuery('.column-flex').each(function () {
            const input = jQuery(this).find('input');
            if (input.data('required') === true) {
                const errorMessage = jQuery(this).find('.error-message');
                if (!String(input.val() ?? '').trim()) {
                    e.preventDefault();
                    (input[0] as HTMLInputElement).setCustomValidity('');
                    errorMessage.show();
                    isValid = false;
                } else {
                    (input[0] as HTMLInputElement).setCustomValidity('');
                    errorMessage.hide();
                }
            }
        });

        return isValid;
    });

    jQuery('.column-flex input').on('input', function () {
        const errorMessage = jQuery(this).closest('.column-flex').find('.error-message');
        (jQuery(this)[0] as HTMLInputElement).setCustomValidity('');
        errorMessage.hide();
    });
});

function toggle_mode(mode: string): void {
    setCookie('mode', mode, 30);
    if (mode === 'dark') {
        jQuery('#toggle_mode_light').hide();
        jQuery('#toggle_mode_dark').show();
        jQuery('#mode').addClass('dark').removeClass('light');
        jQuery('#piwigo-logo').attr('src', url_logo_dark);
    } else {
        jQuery('#toggle_mode_dark').hide();
        jQuery('#toggle_mode_light').show();
        jQuery('#mode').addClass('light').removeClass('dark');
        jQuery('#piwigo-logo').attr('src', url_logo_light);
    }
}

function setCookie(cname: string, cvalue: string, exdays: number): void {
    const d = new Date();
    d.setTime(d.getTime() + exdays * 24 * 60 * 60 * 1000);
    const expires = 'expires=' + d.toUTCString();
    document.cookie = cname + '=' + cvalue + ';' + expires + ';path=/';
    if (cname === 'lang') {
        location.reload();
    }
}

function getCookie(cname: string): string {
    const name = cname + '=';
    const decodedCookie = decodeURIComponent(document.cookie);
    const ca = decodedCookie.split(';');
    for (let i = 0; i < ca.length; i++) {
        let c = ca[i];
        while (c.charAt(0) === ' ') c = c.substring(1);
        if (c.indexOf(name) === 0) return c.substring(name.length, c.length);
    }
    return '';
}

jQuery('.togglePassword').on('click', function (e) {
    const toggle = jQuery(e.target);
    const input = toggle.siblings('input')[0] as HTMLInputElement;
    if (input.type === 'password') {
        input.type = 'text';
        toggle.css('color', '#ff7700');
    } else {
        input.type = 'password';
        toggle.css('color', '#898989');
    }
});

jQuery('#other-languages a').on('click', function (e) {
    const href = jQuery(e.target).attr('href');
    if (!href) return;
    const clickedUrl = new URL(href, location.href);
    const selectedLang = clickedUrl.searchParams.get('lang');
    if (selectedLang) setCookie('lang', selectedLang, 1);
});
