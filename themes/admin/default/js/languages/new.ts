import { cluetip } from "../../../../default/js/vendor/widgets/cluetip";
import { ready } from "../../../../default/js/vendor/utils/dom";

ready(function () {
  cluetip(document.querySelectorAll(".cluetip"), {
    width: 300,
  });
});
