import { initModule } from './moduleInit.js';
import Sortable from 'sortablejs';
import tippy from 'tippy.js';

export function init(_cfg: Record<string, unknown>): void {
    function checkOrderOptions(): void {
        const opts = document.getElementById('image_order_user_define_options');
        if (!opts) return;
        const checked = document.querySelector<HTMLInputElement>('input[name=image_order_choice]:checked');
        opts.style.display = (checked && checked.value === 'user_define') ? '' : 'none';
    }

    const thumbnailsUl = document.querySelector<HTMLElement>('ul.thumbnails');
    if (thumbnailsUl) {
        Sortable.create(thumbnailsUl, {
            animation: 150,
            handle: '.rank-of-image',
            onEnd: function () {
                thumbnailsUl.querySelectorAll('li').forEach(function (li, i) {
                    li.querySelectorAll<HTMLInputElement>('input[name^=rank_of_image]').forEach(function (inp) {
                        inp.setAttribute('value', String((i + 1) * 10));
                    });
                });
                const rankRadio = document.getElementById('image_order_rank') as HTMLInputElement | null;
                if (rankRadio) rankRadio.checked = true;
                checkOrderOptions();
            },
        });
    }

    document.querySelectorAll<HTMLInputElement>('input[name=image_order_choice]').forEach(function (el) {
        el.addEventListener('click', checkOrderOptions);
    });

    checkOrderOptions();

    tippy('.thumbnail', { delay: 0, placement: 'top' });
}

initModule(init);
