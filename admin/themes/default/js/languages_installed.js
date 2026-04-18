import { pwgConfirmFollowHref } from './pwgConfirm.js';

document.querySelectorAll(".delete-lang-button").forEach(function(el) {
  var title_msg = window.str_are_you_sure || 'Are you sure?';
  var confirm_msg = window.str_yes_im_sure || 'Yes';
  var cancel_msg = window.str_i_changed_my_mind || 'No';
  var langBox = el.closest(".languageBox");
  var langNameEl = langBox ? langBox.querySelector('.languageName') : null;
  var lang_name = langNameEl ? langNameEl.innerHTML : '';
  pwgConfirmFollowHref(el, {
    alert_title: title_msg.replace("%s", lang_name),
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg
  });
});
