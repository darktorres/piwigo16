import "./common";

import { hide, on, ready, show, val } from "../../../default/js/vendor/dom";
import { selectize } from "../../../default/js/vendor/selectize";
export {};

ready(function () {
  on(document.querySelectorAll("input[name=who]"), "change", function (): void {
    checkWhoOptions();
  });

  checkWhoOptions();

  function checkWhoOptions() {
    const option = String(
      val(document.querySelectorAll("input[name=who]:checked")),
    );
    hide(document.querySelectorAll(".who_option"));
    show(document.querySelectorAll(".who_" + option));
  }

  document
    .querySelectorAll<HTMLSelectElement>(".who_option select")
    .forEach((el) => {
      selectize(el, {
        plugins: ["remove_button"],
      });
    });

  on(
    document.querySelectorAll("form#categoryNotify"),
    "submit",
    function (event: Event): void {
      let who_selected = false;
      const who_option = String(
        val(document.querySelectorAll("input[name=who]:checked")),
      );

      // `option:selected` is jQuery/Sizzle's own extension -- not a real
      // CSS selector, and querySelectorAll throws a SyntaxError on it.
      // `HTMLSelectElement.selectedOptions` is the native equivalent.
      const whoSelect = document.querySelector<HTMLSelectElement>(
        ".who_" + who_option + " select",
      );
      if (whoSelect !== null && whoSelect.selectedOptions.length > 0) {
        who_selected = true;
      }

      if (!who_selected) {
        show(document.querySelectorAll(".actionButtons .errors"));
        event.preventDefault();
      } else {
        hide(document.querySelectorAll(".actionButtons .errors"));
        console.log("form can be submited");
      }
    },
  );
});
