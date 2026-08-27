import "./common";

export {};

jQuery(document).ready(function () {
  function checkOrderOptions() {
    jQuery("#image_order_user_define_options").hide();
    if (
      jQuery("input[name=image_order_choice]:checked").val() === "user_define"
    ) {
      jQuery("#image_order_user_define_options").show();
    }
  }

  jQuery("ul.thumbnails").sortable({
    revert: true,
    opacity: 0.7,
    handle: jQuery(".rank-of-image").add(".rank-of-image img"),
    update: function () {
      jQuery(this)
        .find("li")
        .each(function (i) {
          jQuery(this)
            .find("input[name^=rank_of_image]")
            .each(function () {
              jQuery(this).attr("value", (i + 1) * 10);
            });
        });

      jQuery("#image_order_rank").prop("checked", true);
      checkOrderOptions();
    },
  });

  jQuery("input[name=image_order_choice]").click(function () {
    checkOrderOptions();
  });

  checkOrderOptions();
});
jQuery(document).ready(function () {
  jQuery(".thumbnail").tipTip({
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });
});
