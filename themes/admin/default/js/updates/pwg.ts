import { pwg_getPageString } from "../../../../default/js/pageData";
import { hide, ready, show } from "../../../../default/js/vendor/utils/dom";

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

  document
    .querySelectorAll<HTMLInputElement>('[name="understand"]')
    .forEach((understand) => {
      understand.addEventListener("click", () => {
        const { checked } = understand;
        document
          .querySelectorAll<HTMLInputElement>('[name="submit"]')
          .forEach((submit) => (submit.disabled = !checked));
      });
    });
});
