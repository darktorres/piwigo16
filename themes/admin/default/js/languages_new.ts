import { ready } from "../../../default/js/vendor/dom";

export {};

ready(function () {
  // Still jQuery: cluetip is a library, and this call goes when that library
  // is ported (docs/PLAN.md P49-B group 3).
  jQuery(".cluetip").cluetip({
    width: 300,
    splitTitle: "|",
  });
});
