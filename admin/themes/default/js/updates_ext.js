import { initModule } from './moduleInit.js';
import { pwgConfirm } from './pwgConfirm.js';

export function init(cfg) {
  const { pwgToken, extType, confirmMsg, errorHead, successHead, errorMsg, restoreMsg, titleConfirmUpdateAll, strConfirm, strCancel } = cfg;
  const pwg_token = pwgToken || '';
  const extType_local = extType || '';
  const confirmMsg_local = confirmMsg || 'Are you sure?';
  const errorHead_local = errorHead || 'ERROR';
  const successHead_local = successHead || 'Update Complete';
  const errorMsg_local = errorMsg || 'an error happened';
  const restoreMsg_local = restoreMsg || 'Reset ignored updates';

  let todo = 0;

  // Vanilla sequential AJAX queue
  let _qRunning = false;
  let _qPending = [];
  const queuedManager = {
    add: function(config) {
      _qPending.push(config);
      if (!_qRunning) _qRun();
    }
  };

  function _qRun() {
    if (_qPending.length === 0) { _qRunning = false; return; }
    _qRunning = true;
    const cfg = _qPending.shift();
    autoupdate_bar_toggle(1);
    const params = new URLSearchParams(cfg.data);
    fetch(cfg.url + '?' + params)
      .then(function(r) { return r.json(); })
      .then(function(data) { autoupdate_bar_toggle(-1); if (cfg.success) cfg.success(data); })
      .catch(function(err) { autoupdate_bar_toggle(-1); if (cfg.error) cfg.error(err); })
      .then(function() { _qRun(); });
  }

  function pwgToast(msg, opts) {
    opts = opts || {};
    const d = document.createElement('div');
    d.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;padding:10px 16px;border-radius:4px;color:#fff;max-width:320px;font-size:13px;box-shadow:0 2px 8px rgba(0,0,0,.3)';
    d.style.background = opts.theme === 'success' ? '#4caf50' : '#e53935';
    if (opts.header) {
      const s = document.createElement('strong');
      s.textContent = opts.header + ': ';
      d.appendChild(s);
    }
    d.appendChild(document.createTextNode(msg));
    if (opts.sticky) {
      const btn = document.createElement('button');
      btn.textContent = '×';
      btn.style.cssText = 'margin-left:8px;background:none;border:none;color:#fff;cursor:pointer;font-size:16px;';
      btn.onclick = function() { d.remove(); };
      d.appendChild(btn);
    }
    document.body.appendChild(d);
    if (!opts.sticky) {
      setTimeout(function() {
        d.animate([{opacity:1},{opacity:0}],{duration:400}).onfinish = function() { d.remove(); };
      }, opts.life || 3000);
    }
  }

  function updateAll() {
    document.querySelectorAll('.updateExtension').forEach(function(el) {
      const parentDiv = el.closest('div');
      if (parentDiv && window.getComputedStyle(parentDiv).display === 'block') el.click();
    });
  }

  function ignoreAll() {
    document.querySelectorAll('.ignoreExtension').forEach(function(el) {
      const parentDiv = el.closest('div');
      if (parentDiv && window.getComputedStyle(parentDiv).display === 'block') el.click();
    });
  }

  function resetIgnored() {
    const params = new URLSearchParams({
      method: 'pwg.extensions.ignoreUpdate',
      reset: true,
      type: extType_local,
      pwg_token: pwg_token,
      format: 'json'
    });
    fetch('ws.php?' + params)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data['stat'] == 'ok') {
          document.querySelectorAll(".pluginBox, fieldset").forEach(function(el) { el.style.display = ''; });
          document.querySelectorAll(".pluginBox").forEach(function(el) { el.setAttribute('data-ignored', 'false'); });
          const updateAllEl = document.getElementById("update_all");
          if (updateAllEl) updateAllEl.style.display = '';
          const ignoreAllEl = document.getElementById("ignore_all");
          if (ignoreAllEl) ignoreAllEl.style.display = '';
          const upToDateEl = document.getElementById("up_to_date");
          if (upToDateEl) upToDateEl.style.display = 'none';
          const resetIgnoreEl = document.getElementById("reset_ignore");
          if (resetIgnoreEl) resetIgnoreEl.style.display = 'none';
          const ignoredEl = document.getElementById("ignored");
          if (ignoredEl) ignoredEl.style.display = 'none';
          checkFieldsets();
        }
      });
  }

  function checkFieldsets() {
    const types = ['plugins', 'themes', 'languages'];
    let total = 0;
    let ignored = 0;
    for (let i = 0; i < 3; i++) {
      let nbExtensions = 0;
      document.querySelectorAll("fieldset[data-type=" + types[i] + "] .pluginBox").forEach(function(el) {
        if (el.getAttribute('data-ignored') == 'true') ignored++;
        else nbExtensions++;
      });
      total += nbExtensions;
      if (nbExtensions == 0) {
        const typeEl = document.getElementById(types[i]);
        if (typeEl) typeEl.style.display = 'none';
      }
    }
    if (total == 0) {
      const updateAllEl = document.getElementById("update_all");
      if (updateAllEl) updateAllEl.style.display = 'none';
      const ignoreAllEl = document.getElementById("ignore_all");
      if (ignoreAllEl) ignoreAllEl.style.display = 'none';
      const upToDateEl = document.getElementById("up_to_date");
      if (upToDateEl) upToDateEl.style.display = '';
    }
    if (ignored > 0) {
      const resetIgnoreEl = document.getElementById("reset_ignore");
      if (resetIgnoreEl) resetIgnoreEl.value = restoreMsg_local + ' (' + ignored + ')';
    }
  }

  function updateExtension(type, id, revision) {
    queuedManager.add({
      url: 'ws.php',
      data: { method: 'pwg.extensions.update', type: type, id: id, revision: revision, pwg_token: pwg_token, format: 'json' },
      success: function(data) {
        if (data['stat'] == 'ok') {
          pwgToast(data['result'], { theme: 'success', header: successHead_local, life: 4000 });
          const extEl = document.getElementById(type + "_" + id);
          if (extEl) extEl.remove();
          checkFieldsets();
        } else {
          pwgToast(data['result'], { theme: 'error', header: errorHead_local, sticky: true });
        }
      },
      error: function() {
        pwgToast(errorMsg_local, { theme: 'error', header: errorHead_local, sticky: true });
      }
    });
  }

  function ignoreExtension(type, id) {
    queuedManager.add({
      url: 'ws.php',
      data: { method: 'pwg.extensions.ignoreUpdate', type: type, id: id, pwg_token: pwg_token, format: 'json' },
      success: function(data) {
        if (data['stat'] == 'ok') {
          const extEl = document.getElementById(type + "_" + id);
          if (extEl) { extEl.style.display = 'none'; extEl.setAttribute('data-ignored', 'true'); }
          const resetIgnoreEl = document.getElementById("reset_ignore");
          if (resetIgnoreEl) resetIgnoreEl.style.display = '';
          checkFieldsets();
        }
      }
    });
  }

  function autoupdate_bar_toggle(i) {
    todo = todo + i;
    if ((i == 1 && todo == 1) || (i == -1 && todo == 0)) {
      document.querySelectorAll('.autoupdate_bar').forEach(function(el) {
        el.style.display = el.style.display === 'none' ? '' : 'none';
      });
    }
  }

  checkFieldsets();

  // Expose functions globally for inline onclick handlers
  window.updateExtension = updateExtension;
  window.ignoreExtension = ignoreExtension;
  window.resetIgnored = resetIgnored;
  window.updateAll = updateAll;
  window.ignoreAll = ignoreAll;

  // Update all button confirmation
  const updateAllBtn = document.getElementById("update_all");
  if (updateAllBtn) {
    updateAllBtn.addEventListener('click', function(e) {
      e.preventDefault();
      const title_msg = titleConfirmUpdateAll || "Are you sure you want to update all extensions?";
      const confirm_msg = strConfirm || "Yes, I am sure";
      const cancel_msg = strCancel || "No, I have changed my mind";
      pwgConfirm({
        title: title_msg,
        buttons: {
          confirm: {
            text: confirm_msg,
            btnClass: 'btn-red',
            action: function() {
              updateAll();
            }
          },
          cancel: {
            text: cancel_msg
          }
        }
      });
    });
  }
}

initModule(init);
