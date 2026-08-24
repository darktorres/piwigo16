export {};

jQuery(document).ready(function () {
  jQuery("textarea").css("overflow-y", "hidden");
  // Auto size and auto grow for all text area
  jQuery("textarea").autogrow();
});
