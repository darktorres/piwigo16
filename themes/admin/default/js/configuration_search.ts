export {};

const filters_names = pwg_getPageData("filters_names");

for (const filter_name of filters_names) {
  if (!$("input#" + filter_name + "Filters").is(":checked")) {
    $("#f" + filter_name + "Select, #" + filter_name + "Arrow").hide();
    $("#default_" + filter_name)
      .parent()
      .hide();
  }

  if ($("#f" + filter_name + "Select").val() !== "admins-only") {
    $("#" + filter_name + "AdminIcon").hide();
  }

  if ($("#default_" + filter_name).is(":checked")) {
    $("#default_" + filter_name)
      .parent()
      .addClass("selected-filter-container");
  }

  $("#" + filter_name + "Filters").on("click", function () {
    if ($("input#" + filter_name + "Filters").is(":checked")) {
      $("#f" + filter_name + "Select, #" + filter_name + "Arrow").show();
      $("#default_" + filter_name)
        .parent()
        .show();
      if ($("#f" + filter_name + "Select").val() === "admins-only") {
        $("#" + filter_name + "AdminIcon").show();
      }
    } else {
      $(
        "#f" +
          filter_name +
          "Select, #" +
          filter_name +
          "Arrow, #" +
          filter_name +
          "AdminIcon",
      ).hide();
      $("#default_" + filter_name)
        .parent()
        .hide();
    }
  });

  $("#f" + filter_name + "Select").on("click", function () {
    if ($("#f" + filter_name + "Select").val() === "admins-only") {
      $("#" + filter_name + "AdminIcon").show();
    } else {
      $("#" + filter_name + "AdminIcon").hide();
    }
  });

  $("#default_" + filter_name).on("click", function () {
    if ($("#default_" + filter_name).is(":checked")) {
      $("#default_" + filter_name)
        .parent()
        .addClass("selected-filter-container");
    } else {
      $("#default_" + filter_name)
        .parent()
        .removeClass("selected-filter-container");
    }
  });
}
