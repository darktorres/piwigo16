import type { operations } from "../../../../openapi/client/schema";
import { jConfirm_confirm_options } from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
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
  jQuery(".updateExtension").each(function () {
    if (jQuery(this).parents("div").css("display") === "block")
      jQuery(this).click();
  });
}

function ignoreAll() {
  jQuery(".ignoreExtension").each(function () {
    if (jQuery(this).parents("div").css("display") === "block")
      jQuery(this).click();
  });
}

function resetIgnored() {
  jQuery.ajax({
    type: "POST",
    url: "api/v1/extensions/updates/ignore",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    dataType: "json",
    data: JSON.stringify({ reset: true, type: extType }),
    // 204 No Content -- extensionsIgnoreUpdate's real response has no body.
    success: function (_data: unknown) {
      jQuery(".pluginBox, fieldset").show();
      jQuery(".pluginBox").attr("data-ignored", "false");
      jQuery("#update_all").show();
      jQuery("#ignore_all").show();
      jQuery("#up_to_date").hide();
      jQuery("#reset_ignore").hide();
      jQuery("#ignored").hide();
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
    jQuery("fieldset[data-type=" + types[i] + "] .pluginBox").each(
      function (_index) {
        if (jQuery(this).attr("data-ignored") === "true") ignored++;
        else nbExtensions++;
      },
    );
    total = total + nbExtensions;
    if (nbExtensions === 0) jQuery("#" + types[i]).hide();
  }

  if (total === 0) {
    jQuery("#update_all").hide();
    jQuery("#ignore_all").hide();
    jQuery("#up_to_date").show();
  }
  if (ignored > 0) {
    jQuery("#reset_ignore").val(restoreMsg + " (" + ignored + ")");
  }
}

function updateExtension(type: string, id: string, revision: string) {
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
      jQuery.jGrowl(data["message"], {
        theme: "success",
        header: successHead,
        life: 4000,
        sticky: false,
      });
      jQuery("#" + type + "_" + id).remove();
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

const callback = (
  mutationList: MutationRecord[],
  observer: MutationObserver,
) => {
  for (const mutation of mutationList) {
    if (mutation.type === "childList") {
      const popup = jQuery("#jGrowl").children();
      for (let i = 0; i < popup.length; i++) {
        if (jQuery(popup[i]!).hasClass("success")) {
          if (
            !jQuery(popup[i]!)
              .children(":first")
              .hasClass("jGrowl-popup-icon icon-ok")
          ) {
            jQuery(popup[i]!).prepend(
              '<div class="jGrowl-popup-icon icon-ok"></div>',
            );
          }
        }

        if (jQuery(popup[i]!).hasClass("error")) {
          if (
            !jQuery(popup[i]!)
              .children(":first")
              .hasClass("jGrowl-popup-icon icon-cancel")
          ) {
            jQuery(popup[i]!).prepend(
              '<div class="jGrowl-popup-icon icon-cancel"></div>',
            );
          }
        }
      }
    }
  }
};

const observer = new MutationObserver(callback);
observer.observe(targetNode!, config);

function ignoreExtension(type: string, id: string) {
  queuedManager.add({
    type: "POST",
    url: "api/v1/extensions/updates/ignore",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    dataType: "json",
    data: JSON.stringify({ type: type, id: id }),
    // 204 No Content -- extensionsIgnoreUpdate's real response has no body.
    success: function (_data: unknown) {
      jQuery("#" + type + "_" + id).hide();
      jQuery("#" + type + "_" + id).attr("data-ignored", "true");
      jQuery("#reset_ignore").show();
      checkFieldsets();
    },
  });
}

function autoupdate_bar_toggle(i: number) {
  todo = todo + i;
  if ((i === 1 && todo === 1) || (i === -1 && todo === 0))
    jQuery(".autoupdate_bar").toggle();
}

checkFieldsets();

const confirm_msg = pwg_getPageString("Yes, I am sure");
const cancel_msg = pwg_getPageString("No, I have changed my mind");
$("#update_all").click(function () {
  const title_msg = pwg_getPageString(
    "Are you sure you want to update all extensions?",
  );
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
