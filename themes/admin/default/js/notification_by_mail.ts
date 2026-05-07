export {};

function setAllCheckboxes(checked: boolean) {
    document
        .querySelectorAll<HTMLInputElement>('#notification_by_mail input[type=checkbox]')
        .forEach((cb) => {
            cb.checked = checked;
        });
}

document.getElementById('checkAllLink')?.addEventListener('click', (e) => {
    e.preventDefault();
    setAllCheckboxes(true);
});

document.getElementById('uncheckAllLink')?.addEventListener('click', (e) => {
    e.preventDefault();
    setAllCheckboxes(false);
});
