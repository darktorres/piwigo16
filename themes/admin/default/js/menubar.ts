import "./common";

import {
  addClass,
  css,
  hide,
  on,
  ready,
  removeClass,
  show,
} from "../../../default/js/vendor/utils/dom";
import {
  sortable,
  sortableToArray,
} from "../../../default/js/vendor/widgets/sortable";

ready(function () {
  hide(document.querySelectorAll(".menuPos"));
  show(document.querySelectorAll(".drag_button"));
  css(document.querySelectorAll(".menuLi"), "cursor", "move");
  sortable(document.querySelectorAll(".menuUl"), {
    axis: "y",
    opacity: 0.8,
  });
  on(
    document.querySelectorAll("input[name^='hide_']"),
    "click",
    function (this: HTMLInputElement): void {
      const men = this.name.split("hide_");
      if (this.checked) {
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
    const ar = sortableToArray(document.querySelector(".menuUl")!);
    for (let i = 0; i < ar.length; i++) {
      const men = ar[i]!.split("menu_");
      document.querySelector<HTMLInputElement>(
        '[name="pos_' + men[1]! + '"]',
      )!.value = String(i + 1);
    }
  });
});
