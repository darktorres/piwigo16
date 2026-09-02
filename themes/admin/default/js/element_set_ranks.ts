import "./common";

import {
  attr,
  hide,
  on,
  ready,
  show,
  val,
} from "../../../default/js/vendor/dom";
import { sortable } from "../../../default/js/vendor/sortable";
import { tipTip } from "../../../default/js/vendor/tiptip";

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

  sortable(document.querySelectorAll("ul.thumbnails"), {
    opacity: 0.7,
    handle: ".rank-of-image, .rank-of-image img",
    update: function (container: HTMLElement) {
      container.querySelectorAll("li").forEach((li, i) => {
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
