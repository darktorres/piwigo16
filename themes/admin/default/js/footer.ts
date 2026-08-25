export {};

// hide_user_whats_new/show_user_whats_new are called from
// layout.latte's own `onclick="show_user_whats_new()"` / `onClick=
// "hide_user_whats_new()"` attributes -- the `javascript:`/`onclick=`
// pattern (docs/PLAN.md P46-C's own finding) needs the same `window.X
// = X` exposure as a cross-file bare read, once this file's own
// top-level declarations become IIFE-private at build time.
jQuery(".tiptip").tipTip({
  delay: 0,
  fadeIn: 200,
  fadeOut: 200,
});

jQuery("a.externalLink").click(function () {
  window.open(jQuery(this).attr("href"));
  return false;
});

function hide_user_whats_new() {
  $.ajax({
    url:
      "api/v1/session/preferences/show_whats_new_" +
      pwg_getPageData<string>("whats_new_major_version"),
    type: "PUT",
    contentType: "application/json",
    dataType: "JSON",
    data: JSON.stringify({
      value: JSON.stringify(false),
      isJson: true,
    }),
  });
  $("#whats_new").hide();
}

function show_user_whats_new() {
  $("#whats_new").show();
}

if (pwg_getPageData<boolean>("show_whats_new")) {
  show_user_whats_new();
}

window.hide_user_whats_new = hide_user_whats_new;
window.show_user_whats_new = show_user_whats_new;
