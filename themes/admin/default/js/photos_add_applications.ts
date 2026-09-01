import { colorbox } from "../../../default/js/vendor/colorbox";
import { ready } from "../../../default/js/vendor/dom";

export {};

ready(function () {
  colorbox(document.querySelectorAll(".illustration a"), { rel: "group1" });
});
