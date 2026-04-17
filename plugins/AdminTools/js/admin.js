/* Graphical checkbox/radio toggle for the AdminTools config form */
document.querySelectorAll('#ato-config input[type=checkbox]').forEach(function (cb) {
    cb.addEventListener('change', function () {
        var prev = cb.previousElementSibling;
        if (prev) {
            prev.classList.toggle('icon-check');
            prev.classList.toggle('icon-check-empty');
        }
    });
});

document.querySelectorAll('#ato-config input[type=radio]').forEach(function (radio) {
    radio.addEventListener('change', function () {
        document.querySelectorAll('#ato-config input[type=radio][name="' + radio.name + '"]').forEach(function (r) {
            var prev = r.previousElementSibling;
            if (prev) {
                prev.classList.toggle('icon-check');
                prev.classList.toggle('icon-check-empty');
            }
        });
    });
});
