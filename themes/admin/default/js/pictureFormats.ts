import { jConfirmConfirmOptions } from "./jconfirmPresets";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/pageData";
import { ajax, AjaxError } from "../../../default/js/vendor/utils/ajax";
import { confirm } from "../../../default/js/vendor/widgets/jconfirm";
import {
  attr,
  data,
  fadeOut,
  htmlOf,
  on,
  remove,
  show,
} from "../../../default/js/vendor/utils/dom";

const pwgToken = pwg_getPageData<string>("csrf_token");
const strConfirmDeleteFormat = pwg_getPageString("Delete %s format ?");
const strConfirmMsg = pwg_getPageString("Yes, I am sure");
const strCancelMsg = pwg_getPageString("No, I have changed my mind");

function fitExtensions() {
  document.querySelectorAll(".format-card-ext span").forEach((node) => {
    const size = Math.min((180 * 1) / node.innerHTML.length, 45);
    node.setAttribute("style", `font-size:${size}px`);
  });
}

fitExtensions();

document.querySelectorAll(".format-card").forEach((card) => {
  const button = card.querySelectorAll(".format-delete");
  on(button, "click", () => {
    confirm({
      title: strConfirmDeleteFormat.replace(
        "%s",
        htmlOf(card.querySelectorAll(".format-card-ext span")) ?? "",
      ),
      content: "",
      buttons: {
        confirm: {
          text: strConfirmMsg,
          btnClass: "btn-red",
          action: function () {
            void deleteFormat(card);
          },
        },
        cancel: {
          text: strCancelMsg,
        },
      },
      ...jConfirmConfirmOptions,
    });
  });
});

async function deleteFormat(card: Element): Promise<void> {
  attr(
    card.querySelectorAll(".format-delete i"),
    "class",
    "icon-spin6 animate-spin",
  );

  try {
    // 204 No Content -- imageFormatDelete's real response has no body.
    await ajax({
      url: "api/v1/images/formats/actions/delete",
      type: "POST",
      json: {
        // `data-id` is a real attribute in picture_formats.latte, never
        // written from JS, so the helper's store and jQuery's agree here --
        // both just coerce the same attribute.
        formatIds: [Number(data(card, "id"))],
      },
      headers: { "X-CSRF-Token": pwgToken },
    });

    fadeOut(card, "slow", () => {
      remove(card);
      if (document.querySelectorAll(".format-card").length === 0) {
        show(document.querySelectorAll(".no-formats"));
      }
    });
  } catch (e) {
    console.error(e instanceof AjaxError ? e.responseText : e);
  }
}
