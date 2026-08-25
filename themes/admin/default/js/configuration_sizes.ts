import "./common?dup";

import { pwg_getPageString } from "../../../default/js/page-data?dup";
export {};

const title_msg = pwg_getPageString(
  "Are you sure you want to restore to default settings?",
);
const confirm_msg = pwg_getPageString("Yes, I am sure");
const cancel_msg = pwg_getPageString("No, I have changed my mind");

$(".restore-settings-button").each(function () {
  $(this).pwg_jconfirm_follow_href({
    alert_title: title_msg,
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg,
  });
});

(function () {
  const labelMaxWidth = pwg_getPageString("Maximum width"),
    labelWidth = pwg_getPageString("Width"),
    labelMaxHeight = pwg_getPageString("Maximum height"),
    labelHeight = pwg_getPageString("Height");

  function toggleResizeFields(_size: string) {
    const checkbox = jQuery("[name=original_resize]");
    const needToggle = jQuery("#sizeEdit-original");

    if (jQuery(checkbox).is(":checked")) {
      needToggle.show();
    } else {
      needToggle.hide();
    }
  }

  toggleResizeFields("original");
  jQuery("[name=original_resize]").click(function () {
    toggleResizeFields("original");
  });

  jQuery("a[id^='sizeEditOpen-']").click(function () {
    const sizeName = jQuery(this).attr("id")!.split("-")[1];
    jQuery("#sizeEdit-" + sizeName).toggle();
    jQuery(this).hide();
    return false;
  });

  jQuery(".cropToggle").click(function () {
    const labelBoxWidth = jQuery(this)
      .parents("table.sizeEditForm")
      .find("td.sizeEditWidth");
    const labelBoxHeight = jQuery(this)
      .parents("table.sizeEditForm")
      .find("td.sizeEditHeight");

    if (jQuery(this).is(":checked")) {
      jQuery(labelBoxWidth).html(labelWidth);
      jQuery(labelBoxHeight).html(labelHeight);
    } else {
      jQuery(labelBoxWidth).html(labelMaxWidth);
      jQuery(labelBoxHeight).html(labelMaxHeight);
    }
  });

  jQuery("#showDetails").click(function () {
    jQuery(".sizeDetails").show();
    jQuery(this).css("visibility", "hidden");
    return false;
  });
})();
