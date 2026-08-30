import "./common";

import { pwg_getPageString } from "../../../default/js/page-data";
import { htmlOf } from "../../../default/js/vendor/dom";
export {};

document.querySelectorAll(".delete-lang-button").forEach(function (button) {
  const title_msg = pwg_getPageString(
    'Are you sure you want to delete the language "%s"?',
  );
  const confirm_msg = pwg_getPageString("Yes, I am sure");
  const cancel_msg = pwg_getPageString("No, I have changed my mind");
  // `.closest(...).find(...)` is Element.closest() plus a scoped query. The
  // `?? ""` covers a box with no name node, where jQuery's own .html()
  // returned undefined and the replace() below spliced the literal string
  // "undefined" into the prompt -- unreachable in the real markup, since a
  // language box always renders its name, but "" is the honest fallback.
  const lang_name =
    htmlOf(
      button.closest(".languageBox")?.querySelectorAll(".languageName") ?? [],
    ) ?? "";
  // Still jQuery: pwg_jconfirm_follow_href wraps jquery-confirm, ported in
  // P49-B group 5.
  $(button).pwg_jconfirm_follow_href({
    alert_title: title_msg.replace("%s", lang_name),
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg,
  });
});
