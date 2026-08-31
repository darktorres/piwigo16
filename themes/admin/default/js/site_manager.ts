import { pwg_jconfirm_follow_href } from "./common";

import { pwg_getPageString } from "../../../default/js/page-data";
import { hide, on, ready, show } from "../../../default/js/vendor/dom";

export {};

ready(function () {
  // A single link, but queried as a set: the "create a site" block is only
  // rendered when synchronization is enabled, so on a gallery with it off
  // there is no node here at all and on() must tolerate an empty set --
  // which is exactly what jQuery's own no-op-on-empty behaviour did.
  on(
    document.querySelectorAll("#showCreateSite a"),
    "click",
    function (): void {
      hide(document.querySelectorAll("#showCreateSite"));
      show(document.querySelectorAll("#createSite"));
    },
  );
});

const title_msg = pwg_getPageString(
  "Are you sure you want to delete this site?",
);
const confirm_msg = pwg_getPageString("Yes, I am sure");
const cancel_msg = pwg_getPageString("No, I have changed my mind");
document.querySelectorAll(".delete-site-button").forEach((button) => {
  pwg_jconfirm_follow_href(button, {
    alert_title: title_msg,
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg,
  });
});
