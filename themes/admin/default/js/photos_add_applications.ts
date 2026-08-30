import { ready } from "../../../default/js/vendor/dom";

export {};

ready(function () {
  // Still jQuery: colorbox is a library (docs/PLAN.md P49-B group 3).
  jQuery(".illustration a").colorbox({ rel: "group1" });
});
