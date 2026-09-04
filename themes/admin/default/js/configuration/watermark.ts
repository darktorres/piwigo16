import "../common";

import { pwg_getPageData } from "../../../../default/js/page-data";
import { hide, show, toggle } from "../../../../default/js/vendor/dom";

(function () {
  const select = document.querySelector<HTMLSelectElement>("#wSelect");
  const image = document.getElementById("wImg");

  function onWatermarkChange() {
    if (image === null) {
      return;
    }

    // jQuery's `.val()` on an empty set is undefined, which this code then
    // stringified to the literal "undefined" -- a truthy length. Unreachable:
    // #wSelect and #wImg come from the same template block, so neither can
    // exist without the other.
    const val = select === null ? "" : select.value;
    if (val.length) {
      image.setAttribute("src", pwg_getPageData<string>("root_url") + val);
      show(image);
    } else {
      hide(image);
    }
  }

  onWatermarkChange();

  select?.addEventListener("change", onWatermarkChange);

  const positionDetails = document.getElementById("positionCustomDetails");
  const positionInputs = document.querySelectorAll<HTMLInputElement>(
    "input[name='w[position]']",
  );

  if (
    document.querySelector<HTMLInputElement>(
      "input[name='w[position]']:checked",
    )?.value === "custom"
  ) {
    if (positionDetails !== null) {
      show(positionDetails);
    }
  }

  positionInputs.forEach((input) => {
    input.addEventListener("change", function () {
      if (positionDetails === null) {
        return;
      }

      if (input.value === "custom") {
        show(positionDetails);
      } else {
        hide(positionDetails);
      }
    });
  });

  document.querySelectorAll(".addWatermarkOpen").forEach((opener) => {
    opener.addEventListener("click", function (event) {
      toggle(document.querySelectorAll("#addWatermark, #selectWatermark"));

      // `return false` from a jQuery handler.
      event.preventDefault();
      event.stopPropagation();
    });
  });
})();
