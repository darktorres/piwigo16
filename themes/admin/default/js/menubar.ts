import "./common";

import {
  addClass,
  css,
  hide,
  on,
  ready,
  removeClass,
  show,
} from "../../../default/js/vendor/dom";

export {};

ready(function () {
  hide(document.querySelectorAll(".menuPos"));
  show(document.querySelectorAll(".drag_button"));
  css(document.querySelectorAll(".menuLi"), "cursor", "move");
  // Still jQuery: sortable is a jQuery-UI widget, ported in P49-B group 4.
  jQuery(".menuUl").sortable({
    axis: "y",
    opacity: 0.8,
  });
  on(
    document.querySelectorAll("input[name^='hide_']"),
    "click",
    function (event: Event): void {
      const input = event.currentTarget as HTMLInputElement;
      const men = input.name.split("hide_");
      if (input.checked) {
        addClass(
          document.querySelectorAll("#menu_" + men[1]!),
          "menuLi_hidden",
        );
      } else {
        removeClass(
          document.querySelectorAll("#menu_" + men[1]!),
          "menuLi_hidden",
        );
      }
    },
  );
  on(document.querySelectorAll("#menuOrdering"), "submit", function (): void {
    // Still jQuery: reads the sortable widget's own current DOM order.
    const ar = jQuery(".menuUl").sortable("toArray");
    for (let i = 0; i < ar.length; i++) {
      const men = ar[i]!.split("menu_");
      (
        document.getElementsByName("pos_" + men[1])[0] as HTMLInputElement
      ).value = String(i + 1);
    }
  });
});
