import TomSelect from 'tom-select';

['authors', 'tags', 'categories'].forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    if (!(el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement)) return;
    new TomSelect(el, {
        plugins: ['remove_button'],
        maxOptions: el.querySelectorAll('option').length,
    });
});
