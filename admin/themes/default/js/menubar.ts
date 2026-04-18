import Sortable from 'sortablejs';

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll<HTMLElement>('.menuPos').forEach(el => { el.style.display = 'none'; });
    document.querySelectorAll<HTMLElement>('.drag_button').forEach(el => { el.style.display = ''; });
    document.querySelectorAll<HTMLElement>('.menuLi').forEach(el => { el.style.cursor = 'move'; });

    const menuUl = document.querySelector<HTMLElement>('.menuUl');
    const menuSortable = menuUl ? Sortable.create(menuUl, { animation: 150 }) : null;

    document.querySelectorAll<HTMLInputElement>("input[name^='hide_']").forEach(function (el) {
        el.addEventListener('click', function () {
            const men = el.name.split('hide_');
            const menuEl = document.getElementById('menu_' + men[1]);
            if (menuEl) menuEl.classList.toggle('menuLi_hidden', el.checked);
        });
    });

    const menuOrdering = document.getElementById('menuOrdering');
    if (menuOrdering) {
        menuOrdering.addEventListener('submit', function () {
            if (menuSortable) {
                const ar = menuSortable.toArray();
                for (let i = 0; i < ar.length; i++) {
                    const men = ar[i].split('menu_');
                    const posInput = document.getElementsByName('pos_' + men[1])[0] as HTMLInputElement | undefined;
                    if (posInput) posInput.value = String(i + 1);
                }
            }
        });
    }
});
