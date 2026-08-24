export {};

(function () {
  function onWatermarkChange() {
    const val = String(jQuery("#wSelect").val());
    if (val.length) {
      jQuery("#wImg")
        .attr("src", pwg_getPageData("root_url") + val)
        .show();
    } else {
      jQuery("#wImg").hide();
    }
  }

  onWatermarkChange();

  jQuery("#wSelect").bind("change", onWatermarkChange);

  if (jQuery("input[name='w[position]']:checked").val() === "custom") {
    jQuery("#positionCustomDetails").show();
  }

  jQuery("input[name='w[position]']").change(function () {
    if (jQuery(this).val() === "custom") {
      jQuery("#positionCustomDetails").show();
    } else {
      jQuery("#positionCustomDetails").hide();
    }
  });

  jQuery(".addWatermarkOpen").click(function () {
    jQuery("#addWatermark, #selectWatermark").toggle();
    return false;
  });
})();
