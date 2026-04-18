import { pwgConfirmFollowHref } from './pwgConfirm.js';

const _docReady = function(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); };

_docReady(function() {
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

  const title_msg = window.str_confirm_delete_site || 'Are you sure you want to delete this site?';
  const confirm_msg = window.str_yes_sure || 'Yes, I am sure';
  const cancel_msg = window.str_no_changed_mind || 'No, I have changed my mind';

  document.querySelectorAll(".delete-site-button").forEach(function(el) {
    pwgConfirmFollowHref(el, {
      alert_title: title_msg,
      alert_confirm: confirm_msg,
      alert_cancel: cancel_msg
    });
  });
});
