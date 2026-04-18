import { initModule } from './moduleInit.js';

interface CommentsConfig {
    nbTotal?: number;
}

export function init(cfg: CommentsConfig): void {
    const { nbTotal } = cfg;

    const h1 = document.querySelector('h1');
    if (h1) h1.insertAdjacentHTML('beforeend', "<span class='badge-number'>" + nbTotal + '</span>');

    function highlightComments(): void {
        document.querySelectorAll('.checkComment').forEach(function (el) {
            const parent = el.closest('tr');
            const checkbox = el.querySelector<HTMLInputElement>('input[type=checkbox]');
            if (parent && checkbox) {
                parent.classList.toggle('selectedComment', checkbox.checked);
            }
        });
    }

    document.querySelectorAll('.checkComment').forEach(function (el) {
        el.addEventListener('click', function (event) {
            const checkbox = (el as HTMLElement).querySelector<HTMLInputElement>('input[type=checkbox]');
            if ((event.target as HTMLInputElement).type !== 'checkbox' && checkbox) {
                checkbox.checked = !checkbox.checked;
            }
            highlightComments();
        });
    });

    const selectAllBtn = document.getElementById('commentSelectAll');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function (event) {
            event.preventDefault();
            document.querySelectorAll<HTMLInputElement>('.checkComment input[type=checkbox]').forEach(el => { el.checked = true; });
            highlightComments();
        });
    }

    const selectNoneBtn = document.getElementById('commentSelectNone');
    if (selectNoneBtn) {
        selectNoneBtn.addEventListener('click', function (event) {
            event.preventDefault();
            document.querySelectorAll<HTMLInputElement>('.checkComment input[type=checkbox]').forEach(el => { el.checked = false; });
            highlightComments();
        });
    }

    const selectInvertBtn = document.getElementById('commentSelectInvert');
    if (selectInvertBtn) {
        selectInvertBtn.addEventListener('click', function (event) {
            event.preventDefault();
            document.querySelectorAll<HTMLInputElement>('.checkComment input[type=checkbox]').forEach(el => { el.checked = !el.checked; });
            highlightComments();
        });
    }
}

initModule(init as (cfg: Record<string, unknown>) => void);
