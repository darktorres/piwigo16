import { css, ready } from "../../../default/js/vendor/dom";

export {};

ready(function () {
  css(document.querySelectorAll("textarea"), "overflow-y", "hidden");
  // Auto size and auto grow for all text area.
  // Still jQuery: autogrow is one of the in-tree micro plugins, ported in
  // P49-B group 1.
  jQuery("textarea").autogrow();
});
