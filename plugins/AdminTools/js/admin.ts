export function initAdminToolsConfig(): void {
    document.querySelectorAll<HTMLInputElement>('#ato-config input[type=checkbox]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            const prev = cb.previousElementSibling;
            if (prev) {
                prev.classList.toggle('icon-check');
                prev.classList.toggle('icon-check-empty');
            }
        });
    });

    document.querySelectorAll<HTMLInputElement>('#ato-config input[type=radio]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.querySelectorAll<HTMLInputElement>('#ato-config input[type=radio][name="' + radio.name + '"]').forEach(function (r) {
                const prev = r.previousElementSibling;
                if (prev) {
                    prev.classList.toggle('icon-check');
                    prev.classList.toggle('icon-check-empty');
                }
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', initAdminToolsConfig);
