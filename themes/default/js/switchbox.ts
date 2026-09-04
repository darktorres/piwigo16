import {
  css,
  hide,
  on,
  outerHeight,
  outerWidth,
  position,
  toggle,
  windowWidth,
} from "./vendor/dom";

export function registerSwitchBox(link: string, box: string): void {
  document.querySelectorAll<HTMLElement>(link).forEach((linkEl) => {
    linkEl.addEventListener("click", function (event) {
      const boxes = document.querySelectorAll<HTMLElement>(box);
      const [first] = boxes;

      // jQuery's dimension getters read the *first* element of a set while
      // its setters write to all of them, and the original relies on both
      // halves of that: one measurement, applied everywhere.
      //
      // The box is still hidden at this point, and a hidden element has
      // no box to measure. The helper reproduces jQuery's display swap
      // for exactly this call: force layout invisibly, measure, restore.
      // Without it the width reads 0 and the popup pins itself flush to
      // the right edge of the viewport instead of 5px inside it.
      css(
        boxes,
        "left",
        Math.min(
          position(linkEl).left,
          windowWidth() -
            (first === undefined ? 0 : outerWidth(first, true)) -
            5,
        ),
      );
      // Re-measured rather than hoisted above the first write, because the
      // original does: a caller is free to pass a link that lives inside
      // its own box, and then the write moves what the second read sees.
      css(boxes, "top", position(linkEl).top + outerHeight(linkEl, true));
      toggle(boxes);

      // `return false` from a jQuery handler, which is both of these.
      event.preventDefault();
      event.stopPropagation();
    });
  });

  document.querySelectorAll<HTMLElement>(box).forEach((boxEl) => {
    on(boxEl, "mouseleave click", function () {
      hide(boxEl);
    });
  });
}
