import { getPageData } from './page-data';
import { config } from './config';

interface PictureFormatsPageData {
    pwg_token: string;
    str_confirm_delete_format: string;
    str_confirm_msg: string;
    str_cancel_msg: string;
}

const { pwg_token, str_confirm_delete_format, str_confirm_msg, str_cancel_msg } =
    getPageData<PictureFormatsPageData>();

function fitExtensions(): void {
    document.querySelectorAll<HTMLElement>('.format-card-ext span').forEach((el) => {
        const size = Math.min(180 * (1 / el.innerHTML.length), 45);
        el.style.fontSize = `${size}px`;
    });
}

fitExtensions();

document.querySelectorAll<HTMLElement>('.format-card').forEach((card) => {
    card.querySelector<HTMLElement>('.format-delete')!.addEventListener('click', () => {
        const extText = card.querySelector<HTMLElement>('.format-card-ext span')?.innerHTML ?? '';
        if (!window.confirm(str_confirm_delete_format.replace('%s', extText))) return;
        deleteFormat(card);
    });
});

function deleteFormat(card: HTMLElement): void {
    const icon = card.querySelector('.format-delete i');
    if (icon) icon.className = 'icon-spin6 animate-spin';
    fetch(config.wsUrl + 'format=json&method=pwg.images.formats.delete', {
        method: 'POST',
        body: new URLSearchParams({ pwg_token, format_id: card.dataset['id'] ?? '' }),
    })
        .then(() => {
            card.style.transition = 'opacity 0.6s';
            card.style.opacity = '0';
            setTimeout(() => {
                card.remove();
                if (document.querySelectorAll('.format-card').length === 0) {
                    document.querySelector<HTMLElement>('.no-formats')!.style.display = '';
                }
            }, 600);
        })
        .catch((message) => console.log(message));
}

export {};
