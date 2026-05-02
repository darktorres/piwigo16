import { getPageData } from './page-data';

interface LanguagesInstalledPageData {
    str_delete_language_confirm: string;
}

const { str_delete_language_confirm } = getPageData<LanguagesInstalledPageData>();

document.querySelectorAll<HTMLAnchorElement>('.delete-lang-button').forEach((el) => {
    const lang_name = el.closest('.languageBox')?.querySelector('.languageName')?.innerHTML ?? '';
    el.addEventListener('click', (e) => {
        e.preventDefault();
        if (window.confirm(str_delete_language_confirm.replace('%s', lang_name))) {
            window.location.href = el.getAttribute('href') ?? '';
        }
    });
});
