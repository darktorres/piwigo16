import type { operations } from "../../../../openapi/client/schema";
import { jConfirm_confirm_options } from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import {
  attr,
  attrOf,
  cssValue,
  hide,
  on,
  prepend,
  setVal,
  show,
  toggle,
} from "../../../default/js/vendor/dom";
export {};

// ignoreAll/resetIgnored/updateExtension/ignoreExtension are called
// from updates_ext.latte's own onClick= attributes -- window.X = X
// exposure at the bottom of this file (the javascript:/onclick=
// pattern, docs/PLAN.md P46-C's own finding).
const pwg_token = pwg_getPageData<string>("csrf_token");
const extType = pwg_getPageData<string>("ext_type");
const errorHead = pwg_getPageString("ERROR");
const successHead = pwg_getPageString("Update Complete");
const errorMsg = pwg_getPageString("an error happened");
const restoreMsg = pwg_getPageString("Reset ignored updates");

let todo = 0;
// Still jQuery: jquery.ajaxmanager is a library, ported in P49-B group 2.
const queuedManager = $.manageAjax.create("queued", {
  queue: true,
  maxRequests: 1,
  beforeSend: function () {
    autoupdate_bar_toggle(1);
  },
  complete: function () {
    autoupdate_bar_toggle(-1);
  },
});

function updateAll() {
  document.querySelectorAll(".updateExtension").forEach((el) => {
    const div = el.closest("div");
    if (div !== null && cssValue(div, "display") === "block") {
      (el as HTMLElement).click();
    }
  });
}

function ignoreAll() {
  document.querySelectorAll(".ignoreExtension").forEach((el) => {
    const div = el.closest("div");
    if (div !== null && cssValue(div, "display") === "block") {
      (el as HTMLElement).click();
    }
  });
}

function resetIgnored() {
  void ajax({
    type: "POST",
    url: "api/v1/extensions/updates/ignore",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    dataType: "json",
    data: JSON.stringify({ reset: true, type: extType }),
    // 204 No Content -- extensionsIgnoreUpdate's real response has no body.
    success: function (_data: unknown) {
      show(document.querySelectorAll(".pluginBox, fieldset"));
      attr(document.querySelectorAll(".pluginBox"), "data-ignored", "false");
      show(document.querySelectorAll("#update_all"));
      show(document.querySelectorAll("#ignore_all"));
      hide(document.querySelectorAll("#up_to_date"));
      hide(document.querySelectorAll("#reset_ignore"));
      hide(document.querySelectorAll("#ignored"));
      checkFieldsets();
    },
  });
}

function checkFieldsets() {
  const types = ["plugins", "themes", "languages"];
  let total = 0;
  let ignored = 0;
  let nbExtensions: number;
  for (let i = 0; i < 3; i++) {
    nbExtensions = 0;
    document
      .querySelectorAll("fieldset[data-type=" + types[i] + "] .pluginBox")
      .forEach((el) => {
        if (attrOf(el, "data-ignored") === "true") ignored++;
        else nbExtensions++;
      });
    total = total + nbExtensions;
    if (nbExtensions === 0) hide(document.querySelectorAll("#" + types[i]));
  }

  if (total === 0) {
    hide(document.querySelectorAll("#update_all"));
    hide(document.querySelectorAll("#ignore_all"));
    show(document.querySelectorAll("#up_to_date"));
  }
  if (ignored > 0) {
    setVal(
      document.querySelectorAll("#reset_ignore"),
      restoreMsg + " (" + ignored + ")",
    );
  }
}

function updateExtension(type: string, id: string, revision: string) {
  // Still jQuery: jquery.ajaxmanager is a library, ported in P49-B group 2.
  queuedManager.add({
    type: "POST",
    dataType: "json",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    url: "api/v1/extensions/" + type + "/" + id + "/actions/update",
    data: JSON.stringify({ revision: revision }),
    success: function (
      data: operations["extensionUpdate"]["responses"][200]["content"]["application/json"],
    ) {
      // Still jQuery: jGrowl is a library, ported in P49-B group 3.
      jQuery.jGrowl(data["message"], {
        theme: "success",
        header: successHead,
        life: 4000,
        sticky: false,
      });
      document.querySelectorAll("#" + type + "_" + id).forEach((el) => {
        el.remove();
      });
      checkFieldsets();
    },
    error: function (jqXHR: JQuery.jqXHR) {
      const message =
        (jqXHR.responseJSON as { detail?: string } | undefined)?.detail ??
        errorMsg;
      jQuery.jGrowl(message, {
        theme: "error",
        header: errorHead,
        sticky: true,
      });
    },
  });
}

const targetNode = document.getElementById("theAdminPage");

const config = { attributes: false, childList: true, subtree: true };

// jGrowl (still jQuery, P49-B group 3) builds its own toast popups under
// #jGrowl -- but they're real DOM nodes regardless of which library
// created them, so reading/writing them here is safe over a native
// conversion; nothing here touches jGrowl's own internal state.
const callback = (mutationList: MutationRecord[]) => {
  for (const mutation of mutationList) {
    if (mutation.type === "childList") {
      const popup = document.querySelectorAll("#jGrowl > *");
      popup.forEach((entry) => {
        if (entry.classList.contains("success")) {
          const firstChild = entry.children[0];
          if (
            firstChild === undefined ||
            !(
              firstChild.classList.contains("jGrowl-popup-icon") &&
              firstChild.classList.contains("icon-ok")
            )
          ) {
            prepend(entry, '<div class="jGrowl-popup-icon icon-ok"></div>');
          }
        }

        if (entry.classList.contains("error")) {
          const firstChild = entry.children[0];
          if (
            firstChild === undefined ||
            !(
              firstChild.classList.contains("jGrowl-popup-icon") &&
              firstChild.classList.contains("icon-cancel")
            )
          ) {
            prepend(entry, '<div class="jGrowl-popup-icon icon-cancel"></div>');
          }
        }
      });
    }
  }
};

const observer = new MutationObserver(callback);
observer.observe(targetNode!, config);

function ignoreExtension(type: string, id: string) {
  // Still jQuery: jquery.ajaxmanager is a library, ported in P49-B group 2.
  queuedManager.add({
    type: "POST",
    url: "api/v1/extensions/updates/ignore",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    dataType: "json",
    data: JSON.stringify({ type: type, id: id }),
    // 204 No Content -- extensionsIgnoreUpdate's real response has no body.
    success: function (_data: unknown) {
      hide(document.querySelectorAll("#" + type + "_" + id));
      attr(
        document.querySelectorAll("#" + type + "_" + id),
        "data-ignored",
        "true",
      );
      show(document.querySelectorAll("#reset_ignore"));
      checkFieldsets();
    },
  });
}

function autoupdate_bar_toggle(i: number) {
  todo = todo + i;
  if ((i === 1 && todo === 1) || (i === -1 && todo === 0))
    toggle(document.querySelectorAll(".autoupdate_bar"));
}

checkFieldsets();

const confirm_msg = pwg_getPageString("Yes, I am sure");
const cancel_msg = pwg_getPageString("No, I have changed my mind");
on(document.querySelectorAll("#update_all"), "click", function (): void {
  const title_msg = pwg_getPageString(
    "Are you sure you want to update all extensions?",
  );
  // Still jQuery: jquery-confirm is a library, ported in P49-B group 5.
  $.confirm({
    title: title_msg,
    buttons: {
      confirm: {
        text: confirm_msg,
        btnClass: "btn-red",
        action: function () {
          updateAll();
        },
      },
      cancel: {
        text: cancel_msg,
      },
    },
    ...jConfirm_confirm_options,
  });
});

window.ignoreAll = ignoreAll;
window.resetIgnored = resetIgnored;
window.updateExtension = updateExtension;
window.ignoreExtension = ignoreExtension;
