function setAllCheckboxes(checked: boolean): void {
    document.querySelectorAll<HTMLInputElement>('#c13y input[type=checkbox]').forEach((cb) => {
        cb.checked = checked;
    });
}

document.querySelectorAll<HTMLElement>('[data-c13y-check-all]').forEach((link) => {
    link.addEventListener('click', (e) => {
        e.preventDefault();
        setAllCheckboxes(true);
    });
});

document.querySelectorAll<HTMLElement>('[data-c13y-uncheck-all]').forEach((link) => {
    link.addEventListener('click', (e) => {
        e.preventDefault();
        setAllCheckboxes(false);
    });
});

document.querySelectorAll<HTMLElement>('[data-c13y-check-auto]').forEach((link) => {
    link.addEventListener('click', (e) => {
        e.preventDefault();
        setAllCheckboxes(false);

        const idsAttr = link.getAttribute('data-c13y-check-auto') || '[]';
        let ids: Array<number | string> = [];
        try {
            const parsed: unknown = JSON.parse(idsAttr);
            if (Array.isArray(parsed)) {
                ids = parsed as Array<number | string>;
            }
        } catch {
            return;
        }

        ids.forEach((id) => {
            const cb = document.getElementById(`c13y_selection-${id}`) as HTMLInputElement | null;
            if (cb) {
                cb.checked = true;
            }
        });
    });
});
