{footer_script}<script>
  var pwg_token = '{$PWG_TOKEN}';
  var extType = '{$EXT_TYPE}';
  var confirmMsg  = '{'Are you sure?'|translate|escape:'javascript'}';
  var errorHead   = '{'ERROR'|translate|escape:'javascript'}';
  var successHead = '{'Update Complete'|translate|escape:'javascript'}';
  var errorMsg    = '{'an error happened'|translate|escape:'javascript'}';
  var restoreMsg  = '{'Reset ignored updates'|translate|escape:'javascript'}';

  var todo = 0;

  // Vanilla sequential AJAX queue
  var _qRunning = false;
  var _qPending = [];
  var queuedManager = {
    add: function(config) {
      _qPending.push(config);
      if (!_qRunning) _qRun();
    }
  };
  function _qRun() {
    if (_qPending.length === 0) { _qRunning = false; return; }
    _qRunning = true;
    var cfg = _qPending.shift();
    autoupdate_bar_toggle(1);
    var params = new URLSearchParams(cfg.data);
    fetch(cfg.url + '?' + params)
      .then(function(r) { return r.json(); })
      .then(function(data) { autoupdate_bar_toggle(-1); if (cfg.success) cfg.success(data); })
      .catch(function(err) { autoupdate_bar_toggle(-1); if (cfg.error) cfg.error(err); })
      .then(function() { _qRun(); });
  }

  function pwgToast(msg, opts) {
    opts = opts || {};
    var d = document.createElement('div');
    d.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;padding:10px 16px;border-radius:4px;color:#fff;max-width:320px;font-size:13px;box-shadow:0 2px 8px rgba(0,0,0,.3)';
    d.style.background = opts.theme === 'success' ? '#4caf50' : '#e53935';
    if (opts.header) { var s = document.createElement('strong'); s.textContent = opts.header + ': '; d.appendChild(s); }
    d.appendChild(document.createTextNode(msg));
    if (opts.sticky) {
      var btn = document.createElement('button');
      btn.textContent = '×'; btn.style.cssText = 'margin-left:8px;background:none;border:none;color:#fff;cursor:pointer;font-size:16px;';
      btn.onclick = function() { d.remove(); };
      d.appendChild(btn);
    }
    document.body.appendChild(d);
    if (!opts.sticky) setTimeout(function() {
      d.animate([{ldelim}opacity:1{rdelim},{ldelim}opacity:0{rdelim}],{ldelim}duration:400{rdelim}).onfinish = function() { d.remove(); };
    }, opts.life || 3000);
  }

  function updateAll() {
    document.querySelectorAll('.updateExtension').forEach(function(el) {
      var parentDiv = el.closest('div');
      if (parentDiv && window.getComputedStyle(parentDiv).display === 'block') el.click();
    });
  }

  function ignoreAll() {
    document.querySelectorAll('.ignoreExtension').forEach(function(el) {
      var parentDiv = el.closest('div');
      if (parentDiv && window.getComputedStyle(parentDiv).display === 'block') el.click();
    });
  }

  function resetIgnored() {
    var params = new URLSearchParams({
      method: 'pwg.extensions.ignoreUpdate',
      reset: true,
      type: extType,
      pwg_token: pwg_token,
      format: 'json'
    });
    fetch('ws.php?' + params)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data['stat'] == 'ok') {
          document.querySelectorAll(".pluginBox, fieldset").forEach(function(el) { el.style.display = ''; });
          document.querySelectorAll(".pluginBox").forEach(function(el) { el.setAttribute('data-ignored', 'false'); });
          var updateAllEl = document.getElementById("update_all");
          if (updateAllEl) updateAllEl.style.display = '';
          var ignoreAllEl = document.getElementById("ignore_all");
          if (ignoreAllEl) ignoreAllEl.style.display = '';
          var upToDateEl = document.getElementById("up_to_date");
          if (upToDateEl) upToDateEl.style.display = 'none';
          var resetIgnoreEl = document.getElementById("reset_ignore");
          if (resetIgnoreEl) resetIgnoreEl.style.display = 'none';
          var ignoredEl = document.getElementById("ignored");
          if (ignoredEl) ignoredEl.style.display = 'none';
          checkFieldsets();
        }
      });
  }

  function checkFieldsets() {
    var types = ['plugins', 'themes', 'languages'];
    var total = 0;
    var ignored = 0;
    for (var i = 0; i < 3; i++) {
      var nbExtensions = 0;
      document.querySelectorAll("fieldset[data-type=" + types[i] + "] .pluginBox").forEach(function(el) {
        if (el.getAttribute('data-ignored') == 'true') ignored++;
        else nbExtensions++;
      });
      total += nbExtensions;
      if (nbExtensions == 0) {
        var typeEl = document.getElementById(types[i]);
        if (typeEl) typeEl.style.display = 'none';
      }
    }
    if (total == 0) {
      var updateAllEl = document.getElementById("update_all");
      if (updateAllEl) updateAllEl.style.display = 'none';
      var ignoreAllEl = document.getElementById("ignore_all");
      if (ignoreAllEl) ignoreAllEl.style.display = 'none';
      var upToDateEl = document.getElementById("up_to_date");
      if (upToDateEl) upToDateEl.style.display = '';
    }
    if (ignored > 0) {
      var resetIgnoreEl = document.getElementById("reset_ignore");
      if (resetIgnoreEl) resetIgnoreEl.value = restoreMsg + ' (' + ignored + ')';
    }
  }

  function updateExtension(type, id, revision) {
    queuedManager.add({
      url: 'ws.php',
      data: { method: 'pwg.extensions.update', type: type, id: id, revision: revision, pwg_token: pwg_token, format: 'json' },
      success: function(data) {
        if (data['stat'] == 'ok') {
          pwgToast(data['result'], { theme: 'success', header: successHead, life: 4000 });
          var extEl = document.getElementById(type + "_" + id);
          if (extEl) extEl.remove();
          checkFieldsets();
        } else {
          pwgToast(data['result'], { theme: 'error', header: errorHead, sticky: true });
        }
      },
      error: function() {
        pwgToast(errorMsg, { theme: 'error', header: errorHead, sticky: true });
      }
    });
  }

  function ignoreExtension(type, id) {
    queuedManager.add({
      url: 'ws.php',
      data: { method: 'pwg.extensions.ignoreUpdate', type: type, id: id, pwg_token: pwg_token, format: 'json' },
      success: function(data) {
        if (data['stat'] == 'ok') {
          var extEl = document.getElementById(type + "_" + id);
          if (extEl) { extEl.style.display = 'none'; extEl.setAttribute('data-ignored', 'true'); }
          var resetIgnoreEl = document.getElementById("reset_ignore");
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
</script>{/footer_script}
{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_script id='pwgConfirm' load='footer' path='admin/themes/default/js/pwgConfirm.js'}
{footer_script require='pwgConfirm'}<script>
  const are_you_sure_msg  = '{'Are you sure?'|translate|escape:'javascript'}';
  const confirm_msg = '{"Yes, I am sure"|translate}';
  const cancel_msg = "{"No, I have changed my mind"|translate}";
  var updateAllBtn = document.getElementById("update_all");
  if (updateAllBtn) {
    updateAllBtn.addEventListener('click', function() {
      const title_msg = "{'Are you sure you want to update all extensions?'|translate}";
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
</script>{/footer_script}

{if $isWebmaster == 1}

  <div class="autoupdate_bar">
    <div class="head-button-1 icon-ok-circled" id="update_all">{'Update All'|translate}</div>
    <div class="head-button-2 icon-block" id="ignore_all" onClick="ignoreAll(); return false;">{'Ignore All'|translate}
    </div>
    <div class="head-button-2 icon-ccw" id="reset_ignore" onClick="resetIgnored(); return false;"
      {if !$SHOW_RESET}style="display:none;" {/if}>{'Reset ignored updates'|translate}</div>
  </div>
  <div class="autoupdate_bar" style="display:none;">
    {'Please wait...'|translate}<br><img src="admin/themes/default/images/ajax-loader-bar.gif">
  </div>

  <p id="up_to_date" style="display:none; text-align:left; margin-left:20px;">
    {'All %s are up to date.'|sprintf:$EXT_TYPE|translate}</p>

  {foreach $UPDATES_EXTENSION as $type => $updates}
    {if not empty($updates)}
      <fieldset id="{$type}" class="pluginContainer pluginUpdateContainer line-form" data-type="{$type}">
        <legend>
          {if $type=='plugins'}
            <span class="icon-puzzle icon-green"></span>{'Plugins'|translate}
          {elseif $type=='themes'}
            <span class="icon-brush icon-blue"></span>{'Themes'|translate}
          {elseif $type=='languages'}
            <span class="icon-language icon-purple"></span>{'Languages'|translate}
          {/if}
        </legend>

        {foreach $updates as $extension}
          <div class="pluginBox pluginMiniBox" id="{$type}_{$extension.EXT_ID}" {if $extension.IGNORED}data-ignored="true"
            style="display:none;" {/if}>
            <div class="pluginContent">
              <div class="pluginName">
                {$extension.EXT_NAME}
              </div>
              <div class="pluginDesc" id="desc_{$extension.ID}">
                <span class="plugin-version plugin-version-old icon-flow-branch"
                  title="{"Current Version"|translate}">{$extension.CURRENT_VERSION}</span> <i class="icon-right"></i> <span
                  class="plugin-version icon-flow-branch" title="{"New Version"|translate}">{$extension.NEW_VERSION}</span>
                <div class="plugin-revision-info"><span>{$extension.REV_DESC}</span></div>
                <a href='{$extension.EXT_URL}' target="_blank"
                  class="plugin-update-link icon-info-circled-1">{'More information'|translate}</a>
              </div>
              <div class="pluginActions">
                <a href="#" onClick="updateExtension('{$type}', '{$extension.EXT_ID}', {$extension.REVISION_ID});"
                  class="updateExtension pluginActionLevel1"> <i class="icon-ok-circled"></i> {'Install'|translate}</a>
                <a href="{$extension.URL_DOWNLOAD}" class="pluginActionLevel2"> <i class="icon-download"></i>
                  {'Download'|translate}</a>
                <a href="#" onClick="ignoreExtension('{$type}', '{$extension.EXT_ID}'); return false;"
                  class="ignoreExtension pluginActionLevel2"><i class="icon-block"></i>{'Ignore this update'|translate}</a>
              </div>
            </div>
          </div>
        {/foreach}
      </fieldset>
    {/if}
  {/foreach}
{/if}