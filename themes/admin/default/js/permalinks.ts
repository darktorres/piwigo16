import { pwg_getPageData } from "../../../default/js/page-data";
export {};

jQuery(document).ready(function () {
  $("h1").append(
    "<span class='badge-number'>" +
      pwg_getPageData<number>("nb_cats") +
      "</span>",
  );
  jQuery("#addPermalinkOpen").click(function () {
    jQuery("#addPermalink").show();
    jQuery("#showAddPermalink").hide();
  });

  jQuery("#addPermalinkClose").click(function () {
    jQuery("#addPermalink").hide();
    jQuery("#showAddPermalink").show();
  });
});
