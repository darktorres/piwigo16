import "./common";

import { toggle } from "../../../default/js/vendor/dom";

export {};

(function () {
  const targets: Record<string, string> = {
    'input[name="comments_validation"]': "#email_admin_on_comment_validation",
    'input[name="user_can_edit_comment"]': "#email_admin_on_comment_edition",
    'input[name="user_can_delete_comment"]': "#email_admin_on_comment_deletion",
  };

  function isChecked(selector: string): boolean {
    return document.querySelector(selector)?.matches(":checked") ?? false;
  }

  for (const selector in targets) {
    const target = targets[selector]!;

    toggle(document.querySelectorAll(target), isChecked(selector));

    // The IIFE the original wrapped this in existed to capture `target`
    // against a loop variable that was expected to be function-scoped. It
    // is not: `for (const selector in ...)` gives each iteration its own
    // binding, so both `selector` and `target` are already captured
    // correctly and the wrapper was doing nothing. The comment that used to
    // sit here described a closure bug this code does not have.
    document.querySelectorAll(selector).forEach((input) => {
      input.addEventListener("change", () => {
        toggle(document.querySelectorAll(target), input.matches(":checked"));
      });
    });
  }

  function check_activate_comments() {
    toggle(
      document.querySelectorAll("#comments_param_container"),
      isChecked("input[name=activate_comments]"),
    );
  }

  check_activate_comments();
  document
    .querySelectorAll("input[name=activate_comments]")
    .forEach((input) => {
      input.addEventListener("change", () => {
        check_activate_comments();
      });
    });
})();
