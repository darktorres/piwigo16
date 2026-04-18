import { initModule } from './moduleInit.js';
import { pwgConfirmFollowHref } from './pwgConfirm.js';

export function init(cfg) {
    const { strAreYouSure, strYesImSure, strIChangedMyMind } = cfg;

    document.querySelectorAll(".delete-lang-button").forEach(function(el) {
      var title_msg = strAreYouSure || 'Are you sure?';
      var confirm_msg = strYesImSure || 'Yes';
      var cancel_msg = strIChangedMyMind || 'No';
      var langBox = el.closest(".languageBox");
      var langNameEl = langBox ? langBox.querySelector('.languageName') : null;
      var lang_name = langNameEl ? langNameEl.innerHTML : '';
      pwgConfirmFollowHref(el, {
        alert_title: title_msg.replace("%s", lang_name),
        alert_confirm: confirm_msg,
        alert_cancel: cancel_msg
      });
    });
}

initModule(init);
