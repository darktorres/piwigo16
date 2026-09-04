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

const titleMsg = pwg_getPageString(
  "Are you sure you want to restore to default settings?",
);
const confirmMsg = pwg_getPageString("Yes, I am sure");
const cancelMsg = pwg_getPageString("No, I have changed my mind");

document
  .querySelectorAll(".restore-settings-button")
  .forEach(function (button) {
    pwg_jconfirm_follow_href(button, {
      alert_title: titleMsg,
      alert_confirm: confirmMsg,
      alert_cancel: cancelMsg,
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
    function (this: HTMLElement, event: Event): void {
      const sizeName = this.id.split("-")[1]!;
      toggle(document.querySelectorAll("#sizeEdit-" + sizeName));
      hide(this);
      event.preventDefault();
      event.stopPropagation();
    },
  );

  on(
    document.querySelectorAll(".cropToggle"),
    "click",
    function (this: HTMLElement): void {
      const table = this.closest("table.sizeEditForm");
      const labelBoxWidth =
        table === null ? [] : table.querySelectorAll("td.sizeEditWidth");
      const labelBoxHeight =
        table === null ? [] : table.querySelectorAll("td.sizeEditHeight");

      if (is(this, ":checked")) {
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
    function (this: Element, event: Event): void {
      show(document.querySelectorAll(".sizeDetails"));
      css(this, "visibility", "hidden");
      event.preventDefault();
      event.stopPropagation();
    },
  );
})();
