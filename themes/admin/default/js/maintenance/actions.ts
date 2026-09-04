import { pwg_jconfirm_follow_href } from "../jconfirmPresets";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../../default/js/page-data";
import {
  attr,
  attrOf,
  hide,
  on,
  show,
} from "../../../../default/js/vendor/dom";

const confirmMsg = pwg_getPageString("Yes, I am sure");
const cancelMsg = pwg_getPageString("No, I have changed my mind");
document.querySelectorAll(".lock-gallery-button").forEach(function (button) {
  const galleryTip = pwg_getPageString(
    "A locked gallery is only visible to administrators",
  );
  const lockUrl = pwg_getPageData<string | null>("u_maint_lock_gallery");
  const title =
    lockUrl !== null && lockUrl !== ""
      ? pwg_getPageString("Are you sure you want to lock the gallery?")
      : pwg_getPageString("Are you sure you want to unlock the gallery?");

  pwg_jconfirm_follow_href(button, {
    alert_title: title,
    alert_confirm: confirmMsg,
    alert_cancel: cancelMsg,
    alert_content: galleryTip,
  });
});
document
  .querySelectorAll(".purge-history-detail-button")
  .forEach(function (button) {
    const title = pwg_getPageString("Purge history detail");
    pwg_jconfirm_follow_href(button, {
      alert_title: title,
      alert_confirm: confirmMsg,
      alert_cancel: cancelMsg,
    });
  });
document
  .querySelectorAll(".purge-history-summary-button")
  .forEach(function (button) {
    const title = pwg_getPageString("Purge history summary");
    pwg_jconfirm_follow_href(button, {
      alert_title: title,
      alert_confirm: confirmMsg,
      alert_cancel: cancelMsg,
    });
  });
document
  .querySelectorAll(".purge-search-history-button")
  .forEach(function (button) {
    const title = pwg_getPageString("Purge search history");
    pwg_jconfirm_follow_href(button, {
      alert_title: title,
      alert_confirm: confirmMsg,
      alert_cancel: cancelMsg,
    });
  });
document
  .querySelectorAll(".delete-all-sizes-button")
  .forEach(function (button) {
    const title = pwg_getPageString(
      "Are you sure you want to delete all sizes?",
    );
    pwg_jconfirm_follow_href(button, {
      alert_title: title,
      alert_confirm: confirmMsg,
      alert_cancel: cancelMsg,
    });
  });

on(
  document.querySelectorAll(".delete-size-check"),
  "click",
  function (this: Element): void {
    if (attrOf(this, "data-selected") === "1") {
      attr(this, "data-selected", "0");
      hide(this.querySelectorAll("i"));
    } else {
      attr(this, "data-selected", "1");
      show(this.querySelectorAll("i"));
    }
    this.dispatchEvent(new Event("change", { bubbles: true }));
  },
);
const firstDeleteSizeCheck = document.querySelector(".delete-size-check");
if (firstDeleteSizeCheck !== null) {
  on(firstDeleteSizeCheck, "change", function (): void {
    if (attrOf(firstDeleteSizeCheck, "data-selected") === "1") {
      hide(document.querySelectorAll(".delete-size-check"));
      attr(
        document.querySelectorAll(".delete-size-check"),
        "data-selected",
        "1",
      );
      show(firstDeleteSizeCheck);
    } else {
      show(document.querySelectorAll(".delete-size-check"));
      attr(
        document.querySelectorAll(".delete-size-check"),
        "data-selected",
        "0",
      );
    }
  });
}
const deleteDerivUrl = "admin.php?page=maintenance&action=derivatives&";
on(
  document.querySelectorAll(".delete-size-check"),
  "change",
  function (): void {
    const deleteDerivWithToken =
      deleteDerivUrl +
      "pwg_token=" +
      pwg_getPageData<string>("pwg_token") +
      "&";
    let typesStr;
    const selected: string[] = [];
    document.querySelectorAll(".delete-size-check").forEach((el) => {
      if (attrOf(el, "data-selected") === "1") {
        selected.push(attrOf(el, "name")!);
      }
    });
    if (selected.length === 0) {
      attr(document.querySelectorAll(".delete-sizes"), "href", "");
    } else {
      if (selected[0] === "all") {
        typesStr = "all";
      } else {
        typesStr = selected.join("_");
      }
      attr(
        document.querySelectorAll(".delete-sizes"),
        "href",
        deleteDerivWithToken + "type=" + typesStr,
      );
    }
  },
);

hide(document.querySelectorAll(".delete-sizes"));
on(document.querySelectorAll(".delete-size-check"), "click", function (): void {
  let displayDeleteSizes = false;
  document.querySelectorAll(".delete-size-check").forEach((el) => {
    if (attrOf(el, "data-selected") === "1") {
      displayDeleteSizes = true;
    }
  });

  // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real false positive from closure mutation: displayDeleteSizes is set inside the forEach callback above, which the rule doesn't track (same class as dom.ts's stopped).
  if (displayDeleteSizes) {
    show(document.querySelectorAll(".delete-sizes"));
  } else {
    hide(document.querySelectorAll(".delete-sizes"));
  }
});
