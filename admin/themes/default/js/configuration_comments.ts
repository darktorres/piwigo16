import { initModule } from './moduleInit.js';

export function init(_cfg: Record<string, unknown>): void {
    const targets: Record<string, string> = {
        'input[name="comments_validation"]': '#email_admin_on_comment_validation',
        'input[name="user_can_edit_comment"]': '#email_admin_on_comment_edition',
        'input[name="user_can_delete_comment"]': '#email_admin_on_comment_deletion',
    };

    Object.keys(targets).forEach(function (selector) {
        const targetEl = document.querySelector<HTMLElement>(targets[selector]);
        const triggerEl = document.querySelector<HTMLInputElement>(selector);
        if (!targetEl || !triggerEl) return;

        targetEl.style.display = triggerEl.checked ? '' : 'none';

        triggerEl.addEventListener('change', function () {
            targetEl.style.display = (this).checked ? '' : 'none';
        });
    });

    function check_activate_comments(): void {
        const container = document.getElementById('comments_param_container');
        const checkbox = document.querySelector<HTMLInputElement>('input[name=activate_comments]');
        if (container && checkbox) container.style.display = checkbox.checked ? '' : 'none';
    }
    check_activate_comments();
    const activateEl = document.querySelector<HTMLInputElement>('input[name=activate_comments]');
    if (activateEl) activateEl.addEventListener('change', check_activate_comments);
}

initModule(init);
