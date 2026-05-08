const targets: Record<string, string> = {
    'input[name="comments_validation"]': '#email_admin_on_comment_validation',
    'input[name="user_can_edit_comment"]': '#email_admin_on_comment_edition',
    'input[name="user_can_delete_comment"]': '#email_admin_on_comment_deletion',
};

for (const selector in targets) {
    const target = targets[selector]!;

    const targetEl = document.querySelector<HTMLElement>(target);
    const selectorEl = document.querySelector<HTMLInputElement>(selector);
    if (targetEl && selectorEl) {
        targetEl.style.display = selectorEl.checked ? '' : 'none';

        selectorEl.addEventListener('change', () => {
            targetEl.style.display = selectorEl.checked ? '' : 'none';
        });
    }
}

function check_activate_comments() {
    const checkbox = document.querySelector<HTMLInputElement>('input[name=activate_comments]');
    const container = document.getElementById('comments_param_container');
    if (checkbox && container) {
        container.style.display = checkbox.checked ? '' : 'none';
    }
}

check_activate_comments();
const activateCheckbox = document.querySelector<HTMLInputElement>('input[name=activate_comments]');
if (activateCheckbox) {
    activateCheckbox.addEventListener('change', () => {
        check_activate_comments();
    });
}
