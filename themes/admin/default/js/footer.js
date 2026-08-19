jQuery('.tiptip').tipTip({
  delay: 0,
  fadeIn: 200,
  fadeOut: 200
});

jQuery('a.externalLink').click(function() {
  window.open(jQuery(this).attr("href"));
  return false;
});

function hide_user_whats_new() {
  $.ajax({
    url: "api/v1/session/preferences/show_whats_new_" + pwg_getPageData('whats_new_major_version'),
    type: "PUT",
    contentType: "application/json",
    dataType: "JSON",
    data: JSON.stringify({
      value: JSON.stringify(false),
      isJson: true,
    }),
  })
  $('#whats_new').hide();
}

function show_user_whats_new() {
  $('#whats_new').show();
}

if (pwg_getPageData('show_whats_new')) {
  show_user_whats_new()
}
