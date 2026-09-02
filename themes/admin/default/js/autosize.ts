import { css, ready } from "../../../default/js/vendor/dom";
import { autogrow } from "../../../default/js/vendor/autogrow";

ready(function () {
  css(document.querySelectorAll("textarea"), "overflow-y", "hidden");
  // Auto size and auto grow for all text area.
  autogrow(document.querySelectorAll("textarea"));
});
