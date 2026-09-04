import { colorbox } from "../../../default/js/vendor/widgets/colorbox";
import { ready } from "../../../default/js/vendor/utils/dom";

ready(function () {
  colorbox(document.querySelectorAll(".illustration a"), { rel: "group1" });
});
