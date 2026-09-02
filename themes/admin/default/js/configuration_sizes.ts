import { pwg_jconfirm_follow_href } from "./common";

import { pwg_getPageString } from "../../../default/js/page-data";
import {
  css,
  hide,
  html,
  is,
  on,
  show,
  toggle,
} from "../../../default/js/vendor/dom";

const title_msg = pwg_getPageString(
  "Are you sure you want to restore to default settings?",
);
const confirm_msg = pwg_getPageString("Yes, I am sure");
const cancel_msg = pwg_getPageString("No, I have changed my mind");

document
  .querySelectorAll(".restore-settings-button")
  .forEach(function (button) {
    pwg_jconfirm_follow_href(button, {
      alert_title: title_msg,
      alert_confirm: confirm_msg,
      alert_cancel: cancel_msg,
    });
  });

(function () {
  const labelMaxWidth = pwg_getPageString("Maximum width"),
    labelWidth = pwg_getPageString("Width"),
    labelMaxHeight = pwg_getPageString("Maximum height"),
    labelHeight = pwg_getPageString("Height");

  function toggleResizeFields(_size: string) {
    const checkbox = document.querySelectorAll("[name=original_resize]");
    const needToggle = document.querySelectorAll("#sizeEdit-original");

    if (is(checkbox, ":checked")) {
      show(needToggle);
    } else {
      hide(needToggle);
    }
  }

  toggleResizeFields("original");
  on(
    document.querySelectorAll("[name=original_resize]"),
    "click",
    function (): void {
      toggleResizeFields("original");
    },
  );

  on(
    document.querySelectorAll("a[id^='sizeEditOpen-']"),
    "click",
    function (event: Event): void {
      const link = event.currentTarget as HTMLElement;
      const sizeName = link.id.split("-")[1]!;
      toggle(document.querySelectorAll("#sizeEdit-" + sizeName));
      hide(link);
      event.preventDefault();
      event.stopPropagation();
    },
  );

  on(
    document.querySelectorAll(".cropToggle"),
    "click",
    function (event: Event): void {
      const checkbox = event.currentTarget as HTMLElement;
      const table = checkbox.closest("table.sizeEditForm");
      const labelBoxWidth =
        table === null ? [] : table.querySelectorAll("td.sizeEditWidth");
      const labelBoxHeight =
        table === null ? [] : table.querySelectorAll("td.sizeEditHeight");

      if (is(checkbox, ":checked")) {
        html(labelBoxWidth, labelWidth);
        html(labelBoxHeight, labelHeight);
      } else {
        html(labelBoxWidth, labelMaxWidth);
        html(labelBoxHeight, labelMaxHeight);
      }
    },
  );

  on(
    document.querySelectorAll("#showDetails"),
    "click",
    function (event: Event): void {
      show(document.querySelectorAll(".sizeDetails"));
      css(event.currentTarget as Element, "visibility", "hidden");
      event.preventDefault();
      event.stopPropagation();
    },
  );
})();
