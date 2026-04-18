import { initModule } from './moduleInit.js';

export function init(cfg) {
  const targets = {
    'input[name="comments_validation"]': '#email_admin_on_comment_validation',
    'input[name="user_can_edit_comment"]': '#email_admin_on_comment_edition',
    'input[name="user_can_delete_comment"]': '#email_admin_on_comment_deletion'
  };

  Object.keys(targets).forEach(function(selector) {
    const targetEl = document.querySelector(targets[selector]);
    const triggerEl = document.querySelector(selector);
    if (!targetEl || !triggerEl) return;

    targetEl.style.display = triggerEl.checked ? '' : 'none';

    triggerEl.addEventListener('change', function() {
      targetEl.style.display = this.checked ? '' : 'none';
    });
  });

  function check_activate_comments() {
    const container = document.getElementById("comments_param_container");
    const checkbox = document.querySelector("input[name=activate_comments]");
    if (container && checkbox) container.style.display = checkbox.checked ? '' : 'none';
  }
  check_activate_comments();
  const activateEl = document.querySelector("input[name=activate_comments]");
  if (activateEl) activateEl.addEventListener("change", check_activate_comments);
}

initModule(init);
