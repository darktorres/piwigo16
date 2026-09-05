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
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real "input[name^='hide_']" name always has a real suffix after "hide_".
      const men = this.name.split("hide_")[1]!;
      if (this.checked) {
        addClass(document.querySelectorAll("#menu_" + men), "menuLi_hidden");
      } else {
        removeClass(document.querySelectorAll("#menu_" + men), "menuLi_hidden");
      }
    },
  );
  on(document.querySelectorAll("#menuOrdering"), "submit", function (): void {
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- the page's own ".menuUl" element is always real.
    const ar = sortableToArray(document.querySelector(".menuUl")!);
    for (const [i, item] of ar.entries()) {
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real sortable menu item id always has a "menu_" suffix.
      const men = item.split("menu_")[1]!;
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real menu item has a matching "pos_X" input.
      document.querySelector<HTMLInputElement>('[name="pos_' + men + '"]')!.value =
        String(i + 1);
    }
  });
});
