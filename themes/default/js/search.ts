import TomSelect from 'tom-select';

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll<HTMLSelectElement>('#authors, #tags, #categories').forEach(function (select) {
        new TomSelect(select, {
            plugins: ['remove_button'],
            maxOptions: select.querySelectorAll('option').length,
        });
    });
});
