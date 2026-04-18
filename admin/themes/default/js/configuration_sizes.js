import { initModule } from './moduleInit.js';
import { pwgConfirmFollowHref } from './pwgConfirm.js';

export function init(cfg) {
  const { titleMsg, confirmMsg, cancelMsg, labelMaxWidth, labelWidth, labelMaxHeight, labelHeight } = cfg;

  document.querySelectorAll(".restore-settings-button").forEach(function(el) {
    pwgConfirmFollowHref(el, {
      alert_title: titleMsg,
      alert_confirm: confirmMsg,
      alert_cancel: cancelMsg
    });
  });

  function toggleResizeFields(size) {
    const checkbox = document.querySelector("[name=original_resize]");
    const needToggle = document.getElementById("sizeEdit-original");
    if (!checkbox || !needToggle) return;
    needToggle.style.display = checkbox.checked ? '' : 'none';
  }

  toggleResizeFields("original");
  const originalResizeCheckbox = document.querySelector("[name=original_resize]");
  if (originalResizeCheckbox) {
    originalResizeCheckbox.addEventListener('click', function() {
      toggleResizeFields("original");
    });
  }

  document.querySelectorAll("a[id^='sizeEditOpen-']").forEach(function(el) {
    el.addEventListener('click', function(event) {
      event.preventDefault();
      const sizeName = this.id.split("-")[1];
      const sizeEdit = document.getElementById("sizeEdit-" + sizeName);
      if (sizeEdit) sizeEdit.style.display = sizeEdit.style.display === 'none' || sizeEdit.style.display === '' ? 'block' : 'none';
      this.style.display = 'none';
    });
  });

  document.querySelectorAll(".cropToggle").forEach(function(el) {
    el.addEventListener('click', function() {
      const form = this.closest('table.sizeEditForm');
      if (!form) return;
      const labelBoxWidth = form.querySelector('td.sizeEditWidth');
      const labelBoxHeight = form.querySelector('td.sizeEditHeight');
      if (this.checked) {
        if (labelBoxWidth) labelBoxWidth.innerHTML = labelWidth;
        if (labelBoxHeight) labelBoxHeight.innerHTML = labelHeight;
      } else {
        if (labelBoxWidth) labelBoxWidth.innerHTML = labelMaxWidth;
        if (labelBoxHeight) labelBoxHeight.innerHTML = labelMaxHeight;
      }
    });
  });

  const showDetailsBtn = document.getElementById("showDetails");
  if (showDetailsBtn) {
    showDetailsBtn.addEventListener('click', function(event) {
      event.preventDefault();
      document.querySelectorAll(".sizeDetails").forEach(function(el) { el.style.display = ''; });
      this.style.visibility = 'hidden';
    });
  }
}

initModule(init);
