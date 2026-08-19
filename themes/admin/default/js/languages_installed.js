$(".delete-lang-button").each(function() {
  var title_msg = pwg_getPageString('Are you sure you want to delete the language "%s"?');
  var confirm_msg = pwg_getPageString('Yes, I am sure');
  var cancel_msg = pwg_getPageString('No, I have changed my mind');
  var lang_name = $(this).closest(".languageBox").find('.languageName').html();
  $(this).pwg_jconfirm_follow_href({
    alert_title: title_msg.replace("%s", lang_name),
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg
  });
});
