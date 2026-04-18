import { initModule } from './moduleInit.js';
import { pwgConfirmFollowHref } from './pwgConfirm.js';

export function init(cfg) {
    const { strConfirmDeleteSite, strYesSure, strNoChangedMind } = cfg;

    const showCreateSite = document.getElementById("showCreateSite");
    const createSite = document.getElementById("createSite");
    const openLink = showCreateSite ? showCreateSite.querySelector("a") : null;

    if (openLink) {
      openLink.addEventListener('click', function(e) {
        e.preventDefault();
        showCreateSite.style.display = 'none';
        if (createSite) createSite.style.display = '';
      });
    }

    const title_msg = strConfirmDeleteSite || 'Are you sure you want to delete this site?';
    const confirm_msg = strYesSure || 'Yes, I am sure';
    const cancel_msg = strNoChangedMind || 'No, I have changed my mind';

    document.querySelectorAll(".delete-site-button").forEach(function(el) {
      pwgConfirmFollowHref(el, {
        alert_title: title_msg,
        alert_confirm: confirm_msg,
        alert_cancel: cancel_msg
      });
    });
}

initModule(init);
