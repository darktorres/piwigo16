import '../css/pages/menubar.css';

document.querySelectorAll<HTMLElement>('.menuPos').forEach((el) => {
    el.style.display = 'none';
});
document.querySelectorAll<HTMLElement>('.drag_button').forEach((el) => {
    el.style.display = '';
});
document.querySelectorAll<HTMLElement>('.menuLi').forEach((el) => {
    el.style.cursor = 'move';
});

const menuUl = document.querySelector<HTMLUListElement>('.menuUl');
if (menuUl) {
    let dragSrc: HTMLElement | null = null;

    Array.from(menuUl.children).forEach((rawItem) => {
        const item = rawItem as HTMLElement;
        item.setAttribute('draggable', 'true');

        item.addEventListener('dragstart', (e) => {
            dragSrc = item;
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
            }
            item.style.opacity = '0.8';
        });

        item.addEventListener('dragend', () => {
            item.style.opacity = '';
            Array.from(menuUl.children).forEach((li) => {
                (li as HTMLElement).classList.remove('drag-over');
            });
        });

        item.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (e.dataTransfer) {
                e.dataTransfer.dropEffect = 'move';
            }
            return false;
        });

        item.addEventListener('dragenter', () => {
            item.classList.add('drag-over');
        });

        item.addEventListener('dragleave', () => {
            item.classList.remove('drag-over');
        });

        item.addEventListener('drop', (e) => {
            e.stopPropagation();
            if (dragSrc && dragSrc !== item) {
                const items = Array.from(menuUl.children) as HTMLElement[];
                const srcIdx = items.indexOf(dragSrc);
                const tgtIdx = items.indexOf(item);
                if (srcIdx < tgtIdx) {
                    menuUl.insertBefore(dragSrc, item.nextSibling);
                } else {
                    menuUl.insertBefore(dragSrc, item);
                }
            }
            return false;
        });
    });
}

document.querySelectorAll<HTMLInputElement>("input[name^='hide_']").forEach((input) => {
    input.addEventListener('click', () => {
        const men = input.name.split('hide_');
        const menuItem = document.getElementById('menu_' + men[1]);
        if (menuItem) {
            if (input.checked) {
                menuItem.classList.add('menuLi_hidden');
            } else {
                menuItem.classList.remove('menuLi_hidden');
            }
        }
    });
});

const menuOrderingForm = document.getElementById('menuOrdering');
if (menuOrderingForm) {
    menuOrderingForm.addEventListener('submit', () => {
        const items = Array.from(menuUl ? menuUl.children : []) as HTMLElement[];
        for (let i = 0; i < items.length; i++) {
            const men = items[i]!.id.split('menu_');
            const posInput = document.getElementsByName('pos_' + men[1]!)[0] as
                | HTMLInputElement
                | undefined;
            if (posInput) {
                posInput.value = String(i + 1);
            }
        }
    });
}
