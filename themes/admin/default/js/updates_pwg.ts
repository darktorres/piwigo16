import { pwg_getPageString } from "../../../default/js/page-data?dup";
export {};

jQuery(document).ready(function () {
  jQuery('input[name="submit"]').click(function () {
    if (!confirm(pwg_getPageString("Are you sure?"))) return false;
    jQuery(this).hide();
    jQuery(".autoupdate_bar").show();
  });
  jQuery('[name="understand"]').click(function () {
    jQuery('[name="submit"]').prop(
      "disabled",
      !(this as HTMLInputElement).checked,
    );
  });
});
