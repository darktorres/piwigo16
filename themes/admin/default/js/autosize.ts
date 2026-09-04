import { css, ready } from "../../../default/js/vendor/utils/dom";
import { autogrow } from "../../../default/js/vendor/widgets/autogrow";

ready(function () {
  css(document.querySelectorAll("textarea"), "overflow-y", "hidden");
  // Auto size and auto grow for all text area.
  autogrow(document.querySelectorAll("textarea"));
});
