import { pwgConfirmFollowHref } from './pwgConfirm.js';
import GLightbox from 'glightbox';

const title_msg = window.str_delete_theme || 'Are you sure?';
const confirm_msg = window.str_yes_im_sure || 'Yes';
const cancel_msg = window.str_i_changed_my_mind || 'No';

document.querySelectorAll(".delete-theme-button").forEach(function(el) {
  let theme_name = el.closest(".themeBox")?.querySelector(".themeName")?.getAttribute("title") ?? '';
  let title = window.str_delete_theme_msg || 'Are you sure you want to delete the theme "%s"?';
  pwgConfirmFollowHref(el, {
    alert_title: title.replace("%s", theme_name),
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg
  });
});

document.addEventListener('DOMContentLoaded', function() {
  GLightbox({ selector: 'a.preview-box' });

  document.addEventListener('mouseup', function(e) {
    e.stopPropagation();
    if (!e.target.classList.contains('showInfo')) {
      document.querySelectorAll('.showInfo-dropdown').forEach(function(el) { el.style.display = 'none'; });
    }
  });
});

window.addEventListener('load', function() {
  document.querySelectorAll('.themeBox').forEach(function(box) {
    var showInfoBtn = box.querySelector('.showInfo');
    if (showInfoBtn) {
      showInfoBtn.addEventListener('click', function() {
        document.querySelectorAll('.showInfo-dropdown').forEach(function(el) { el.style.display = 'none'; });
        var dropdown = box.querySelector('.showInfo-dropdown');
        if (dropdown) dropdown.style.display = '';
      });
    }

    var screenImage = box.querySelector(".preview-box img");
    var previewBox = box.querySelector(".preview-box");
    if (screenImage && previewBox) {
      let imageW = screenImage.clientWidth;
      let imageH = screenImage.clientHeight;
      let size = previewBox.clientWidth;

      if (imageW > imageH) {
        screenImage.style.height = size + 'px';
        screenImage.style.width = (imageW * size / imageH) + 'px';
      } else {
        screenImage.style.width = size + 'px';
        screenImage.style.height = (imageH * size / imageW) + 'px';
      }
    }
  });
});
