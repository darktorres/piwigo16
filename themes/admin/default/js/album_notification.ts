import "./common?dup";

export {};

jQuery(document).ready(function () {
  jQuery("input[name=who]").change(function () {
    checkWhoOptions();
  });

  checkWhoOptions();

  function checkWhoOptions() {
    const option = String(jQuery("input[name=who]:checked").val());
    jQuery(".who_option").hide();
    jQuery(".who_" + option).show();
  }

  jQuery(".who_option select").selectize({
    plugins: ["remove_button"],
  });

  jQuery("form#categoryNotify").submit(function (e) {
    let who_selected = false;
    const who_option = String(jQuery("input[name=who]:checked").val());

    if (jQuery(".who_" + who_option + " select").length > 0) {
      if (jQuery(".who_" + who_option + " select option:selected").length > 0) {
        who_selected = true;
      }
    }

    if (!who_selected) {
      jQuery(".actionButtons .errors").show();
      e.preventDefault();
    } else {
      jQuery(".actionButtons .errors").hide();
      console.log("form can be submited");
    }
  });
});
