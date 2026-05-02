import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';

function checkOrderOptions(): void {
    const optionsDiv = document.getElementById('image_order_user_define_options');
    const checked = document.querySelector<HTMLInputElement>('input[name=image_order_choice]:checked');
    if (optionsDiv) {
        optionsDiv.style.display = checked?.value === 'user_define' ? '' : 'none';
    }
}

const ul = document.querySelector<HTMLUListElement>('ul.thumbnails');
if (ul) {
    let draggingEl: HTMLElement | null = null;

    function updateRanks(): void {
        Array.from(ul!.querySelectorAll<HTMLLIElement>('li.rank-of-image')).forEach((li, i) => {
            const input = li.querySelector<HTMLInputElement>("input[name^=rank_of_image]");
            if (input) input.value = String((i + 1) * 10);
        });
    }

    Array.from(ul.querySelectorAll<HTMLLIElement>('li.rank-of-image')).forEach((li) => {
        li.setAttribute('draggable', 'true');
        li.style.cursor = 'grab';

        li.addEventListener('dragstart', (e) => {
            draggingEl = li;
            li.style.opacity = '0.5';
            if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
        });

        li.addEventListener('dragend', () => {
            li.style.opacity = '';
            draggingEl = null;
            updateRanks();
            const imageOrderRank = document.getElementById('image_order_rank') as HTMLInputElement | null;
            if (imageOrderRank) imageOrderRank.checked = true;
            checkOrderOptions();
        });

        li.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
            if (draggingEl && draggingEl !== li) {
                const allItems = Array.from(ul.querySelectorAll<HTMLLIElement>('li.rank-of-image'));
                const draggingIdx = allItems.indexOf(draggingEl as HTMLLIElement);
                const targetIdx = allItems.indexOf(li);
                if (draggingIdx < targetIdx) {
                    ul.insertBefore(draggingEl, li.nextSibling);
                } else {
                    ul.insertBefore(draggingEl, li);
                }
            }
        });
    });
}

document.querySelectorAll<HTMLInputElement>('input[name=image_order_choice]').forEach((radio) => {
    radio.addEventListener('click', checkOrderOptions);
});

checkOrderOptions();

tippy('.thumbnail', { delay: [0, 0], duration: [200, 200] });
