document.querySelectorAll<HTMLLabelElement>('#syncFiles label').forEach((label) => {
    label.addEventListener('click', () => {
        const checked = document.querySelector<HTMLInputElement>("input[value='files']:checked");
        const filesInput = document.querySelector<HTMLInputElement>("input[value='files']");
        const ul = filesInput ? filesInput.closest('li') : null;
        const subUl = ul ? ul.querySelector<HTMLElement>('ul') : null;
        if (subUl) {
            subUl.style.display = checked ? '' : 'none';
        }
    });
});
