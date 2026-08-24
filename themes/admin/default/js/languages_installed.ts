export {};

$(".delete-lang-button").each(function () {
  const title_msg = pwg_getPageString(
    'Are you sure you want to delete the language "%s"?',
  );
  const confirm_msg = pwg_getPageString("Yes, I am sure");
  const cancel_msg = pwg_getPageString("No, I have changed my mind");
  const lang_name = $(this)
    .closest(".languageBox")
    .find(".languageName")
    .html();
  $(this).pwg_jconfirm_follow_href({
    alert_title: title_msg.replace("%s", lang_name),
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg,
  });
});
