import "./common";

import {
  attr,
  hide,
  on,
  ready,
  show,
  val,
} from "../../../default/js/vendor/dom";
import { tipTip } from "../../../default/js/vendor/tiptip";
export {};

ready(function () {
  function checkOrderOptions() {
    hide(document.querySelectorAll("#image_order_user_define_options"));
    if (
      val(
        document.querySelectorAll("input[name=image_order_choice]:checked"),
      ) === "user_define"
    ) {
      show(document.querySelectorAll("#image_order_user_define_options"));
    }
  }

  // Still jQuery: sortable is a jQuery-UI widget, ported in P49-B group 4.
  // `update`'s own body below is ordinary DOM work, converted -- jQuery-UI
  // calls it with `this` already bound to the widget's real DOM element.
  jQuery("ul.thumbnails").sortable({
    revert: true,
    opacity: 0.7,
    handle: jQuery(".rank-of-image").add(".rank-of-image img"),
    update: function (this: HTMLElement) {
      this.querySelectorAll("li").forEach((li, i) => {
        li.querySelectorAll("input[name^=rank_of_image]").forEach((input) => {
          attr(input, "value", String((i + 1) * 10));
        });
      });

      const imageOrderRank =
        document.querySelector<HTMLInputElement>("#image_order_rank");
      if (imageOrderRank !== null) {
        imageOrderRank.checked = true;
      }
      checkOrderOptions();
    },
  });

  on(
    document.querySelectorAll("input[name=image_order_choice]"),
    "click",
    function (): void {
      checkOrderOptions();
    },
  );

  checkOrderOptions();
});
ready(function () {
  tipTip(document.querySelectorAll(".thumbnail"), {
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });
});
