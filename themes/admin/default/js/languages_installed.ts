import { pwg_jconfirm_follow_href } from "./common";

import { pwg_getPageString } from "../../../default/js/page-data";
import { htmlOf } from "../../../default/js/vendor/dom";

document.querySelectorAll(".delete-lang-button").forEach(function (button) {
  const titleMsg = pwg_getPageString(
    'Are you sure you want to delete the language "%s"?',
  );
  const confirmMsg = pwg_getPageString("Yes, I am sure");
  const cancelMsg = pwg_getPageString("No, I have changed my mind");
  // `.closest(...).find(...)` is Element.closest() plus a scoped query. The
  // `?? ""` covers a box with no name node, where jQuery's own .html()
  // returned undefined and the replace() below spliced the literal string
  // "undefined" into the prompt -- unreachable in the real markup, since a
  // language box always renders its name, but "" is the honest fallback.
  const langName =
    htmlOf(
      button.closest(".languageBox")?.querySelectorAll(".languageName") ?? [],
    ) ?? "";
  pwg_jconfirm_follow_href(button, {
    alert_title: titleMsg.replace("%s", langName),
    alert_confirm: confirmMsg,
    alert_cancel: cancelMsg,
  });
});
