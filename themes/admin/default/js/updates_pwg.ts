import { pwg_getPageString } from "../../../default/js/page-data";
import { hide, ready, show } from "../../../default/js/vendor/dom";

ready(() => {
  document
    .querySelectorAll<HTMLInputElement>('input[name="submit"]')
    .forEach((submit) => {
      submit.addEventListener("click", (event) => {
        // The original returned false only on decline, so the submit still
        // goes through when confirmed -- `return false` is both
        // preventDefault() and stopPropagation() in jQuery.
        if (!confirm(pwg_getPageString("Are you sure?"))) {
          event.preventDefault();
          event.stopPropagation();

          return;
        }
        hide(submit);
        show(document.querySelectorAll(".autoupdate_bar"));
      });
    });

  document.querySelectorAll('[name="understand"]').forEach((understand) => {
    understand.addEventListener("click", () => {
      const checked = (understand as HTMLInputElement).checked;
      document
        .querySelectorAll<HTMLInputElement>('[name="submit"]')
        .forEach((submit) => (submit.disabled = !checked));
    });
  });
});
