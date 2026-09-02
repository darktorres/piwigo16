import { jConfirm_confirm_options } from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import { confirm } from "../../../default/js/vendor/jconfirm";
import {
  attr,
  data,
  fadeOut,
  htmlOf,
  on,
  remove,
  show,
} from "../../../default/js/vendor/dom";
export {};

const pwg_token = pwg_getPageData<string>("csrf_token");
const str_confirm_delete_format = pwg_getPageString("Delete %s format ?");
const str_confirm_msg = pwg_getPageString("Yes, I am sure");
const str_cancel_msg = pwg_getPageString("No, I have changed my mind");

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
      title: str_confirm_delete_format.replace(
        "%s",
        htmlOf(card.querySelectorAll(".format-card-ext span")) ?? "",
      ),
      content: "",
      buttons: {
        confirm: {
          text: str_confirm_msg,
          btnClass: "btn-red",
          action: function () {
            deleteFormat(card);
          },
        },
        cancel: {
          text: str_cancel_msg,
        },
      },
      ...jConfirm_confirm_options,
    });
  });
});

function deleteFormat(card: Element) {
  attr(
    card.querySelectorAll(".format-delete i"),
    "class",
    "icon-spin6 animate-spin",
  );
  void ajax({
    url: "api/v1/images/formats/actions/delete",
    type: "POST",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    data: JSON.stringify({
      // `data-id` is a real attribute in picture_formats.latte, never
      // written from JS, so the helper's store and jQuery's agree here --
      // both just coerce the same attribute.
      formatIds: [Number(data(card, "id"))],
    }),
    // 204 No Content -- imageFormatDelete's real response has no body.
    success: function (_raw_data: unknown) {
      fadeOut(card, "slow", () => {
        remove(card);
        if (document.querySelectorAll(".format-card").length === 0) {
          show(document.querySelectorAll(".no-formats"));
        }
      });
    },
    error: function (message) {
      console.error(message);
    },
  });
}
