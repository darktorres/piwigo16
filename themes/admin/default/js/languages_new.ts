import { cluetip } from "../../../default/js/vendor/cluetip";
import { ready } from "../../../default/js/vendor/dom";

ready(function () {
  cluetip(document.querySelectorAll(".cluetip"), {
    width: 300,
  });
});
