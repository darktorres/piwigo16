import { pwg_getPageData } from "../../../default/js/page-data";
import { hide, ready, show } from "../../../default/js/vendor/utils/dom";

ready(() => {
  // jQuery's `$("h1").append(...)` appended to every matching heading, not
  // just the first.
  document.querySelectorAll("h1").forEach((heading) => {
    heading.insertAdjacentHTML(
      "beforeend",
      "<span class='badge-number'>" +
        String(pwg_getPageData<number>("nb_cats")) +
        "</span>",
    );
  });

  document.getElementById("addPermalinkOpen")?.addEventListener("click", () => {
    show(document.querySelectorAll("#addPermalink"));
    hide(document.querySelectorAll("#showAddPermalink"));
  });

  document
    .getElementById("addPermalinkClose")
    ?.addEventListener("click", () => {
      hide(document.querySelectorAll("#addPermalink"));
      show(document.querySelectorAll("#showAddPermalink"));
    });
});
