import { getPageData } from './page-data';

interface UpdatesPwgPageData {
    str_are_you_sure: string;
}

const { str_are_you_sure } = getPageData<UpdatesPwgPageData>();

document.querySelectorAll<HTMLInputElement>('input[name="submit"]').forEach((btn) => {
    btn.addEventListener('click', function(this: HTMLInputElement) {
        if (!confirm(str_are_you_sure)) return;
        this.style.display = 'none';
        document.querySelectorAll<HTMLElement>('.autoupdate_bar').forEach((el) => {
            el.style.display = '';
        });
    });
});

document.querySelectorAll<HTMLInputElement>('[name="understand"]').forEach((cb) => {
    cb.addEventListener('click', () => {
        document.querySelectorAll<HTMLInputElement>('[name="submit"]').forEach((btn) => {
            btn.disabled = !cb.checked;
        });
    });
});
