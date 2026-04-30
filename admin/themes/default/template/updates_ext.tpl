{footer_script}
var pwg_token = '{$PWG_TOKEN}';
var extType = '{$EXT_TYPE}';
var confirmMsg  = '{'Are you sure?'|@translate|@escape:'javascript'}';
var errorHead   = '{'ERROR'|@translate|@escape:'javascript'}';
var successHead = '{'Update Complete'|@translate|@escape:'javascript'}';
var errorMsg    = '{'an error happened'|@translate|@escape:'javascript'}';
var restoreMsg  = '{'Reset ignored updates'|@translate|@escape:'javascript'}';

{literal}
var todo = 0;

// Simple sequential async queue replacing $.manageAjax
var _extQueue = Promise.resolve();
var queuedManager = {
  add: function(opts) {
    _extQueue = _extQueue.then(function() {
      if (opts.beforeSend) opts.beforeSend();
      var params = new URLSearchParams(opts.data || {});
      return fetch((opts.url || 'ws.php') + '?' + params.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) { if (opts.success) opts.success(data); })
        .catch(function(err) { if (opts.error) opts.error(err); })
        .finally(function() { if (opts.complete) opts.complete(); });
    });
  }
};

function pwgNotify(msg, theme) {
  var el = document.createElement('div');
  el.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;padding:10px 16px;border-radius:4px;color:#fff;font-size:14px;max-width:320px;margin-bottom:5px;';
  el.style.background = theme === 'success' ? '#27ae60' : '#e74c3c';
  el.innerHTML = (theme === 'success' ? '<i class="icon-ok"></i> ' : '<i class="icon-attention"></i> ') + msg;
  document.body.appendChild(el);
  if (theme === 'success') setTimeout(function() { el.remove(); }, 4000);
}

window.updateAll = function updateAll() {
  document.querySelectorAll('.updateExtension').forEach(function(el) {
    var parent = el.closest('div');
    if (parent && parent.style.display !== 'none') el.click();
  });
}

window.ignoreAll = function ignoreAll() {
  document.querySelectorAll('.ignoreExtension').forEach(function(el) {
    var parent = el.closest('div');
    if (parent && parent.style.display !== 'none') el.click();
  });
}

window.resetIgnored = function resetIgnored() {
  fetch('ws.php?' + new URLSearchParams({ method: 'pwg.extensions.ignoreUpdate', reset: 'true', type: extType, pwg_token: pwg_token, format: 'json' }).toString())
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data['stat'] == 'ok') {
        document.querySelectorAll(".pluginBox, fieldset").forEach(function(el) { el.style.display = ''; });
        document.querySelectorAll(".pluginBox").forEach(function(el) { el.setAttribute('data-ignored', 'false'); });
        ['update_all','ignore_all'].forEach(function(id) { var el = document.getElementById(id); if (el) el.style.display = ''; });
        ['up_to_date','reset_ignore','ignored'].forEach(function(id) { var el = document.getElementById(id); if (el) el.style.display = 'none'; });
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
    if (nbExtensions == 0) { var el = document.getElementById(types[i]); if (el) el.style.display = 'none'; }
  }
  if (total == 0) {
    ['update_all','ignore_all'].forEach(function(id) { var el = document.getElementById(id); if (el) el.style.display = 'none'; });
    var upToDate = document.getElementById('up_to_date'); if (upToDate) upToDate.style.display = '';
  }
  if (ignored > 0) {
    var resetEl = document.getElementById('reset_ignore'); if (resetEl) resetEl.value = restoreMsg + ' (' + ignored + ')';
  }
}

window.updateExtension = function updateExtension(type, id, revision) {
  queuedManager.add({
    beforeSend: function() { autoupdate_bar_toggle(1); },
    url: 'ws.php',
    data: { method: 'pwg.extensions.update', type: type, id: id, revision: revision, pwg_token: pwg_token, format: 'json' },
    success: function(data) {
      if (data['stat'] == 'ok') {
        pwgNotify(data['result'], 'success');
        var extEl = document.getElementById(type + '_' + id); if (extEl) extEl.remove();
        checkFieldsets();
      } else {
        pwgNotify(data['result'], 'error');
      }
    },
    error: function() { pwgNotify(errorMsg, 'error'); },
    complete: function() { autoupdate_bar_toggle(-1); }
  });
}

window.ignoreExtension = function ignoreExtension(type, id) {
  queuedManager.add({
    beforeSend: function() { autoupdate_bar_toggle(1); },
    url: 'ws.php',
    data: { method: 'pwg.extensions.ignoreUpdate', type: type, id: id, pwg_token: pwg_token, format: 'json' },
    success: function(data) {
      if (data['stat'] == 'ok') {
        var extEl = document.getElementById(type + '_' + id);
        if (extEl) { extEl.style.display = 'none'; extEl.setAttribute('data-ignored', 'true'); }
        var resetEl = document.getElementById('reset_ignore'); if (resetEl) resetEl.style.display = '';
        checkFieldsets();
      }
    },
    complete: function() { autoupdate_bar_toggle(-1); }
  });
}

function autoupdate_bar_toggle(i) {
  todo += i;
  if ((i == 1 && todo == 1) || (i == -1 && todo == 0)) {
    document.querySelectorAll('.autoupdate_bar').forEach(function(el) {
      el.style.display = el.style.display === 'none' ? '' : 'none';
    });
  }
}

checkFieldsets();
{/literal}
{/footer_script}
{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{footer_script}

const are_you_sure_msg  = '{'Are you sure?'|@translate|@escape:'javascript'}';
const confirm_msg = '{"Yes, I am sure"|@translate}';
const cancel_msg = "{"No, I have changed my mind"|@translate}";
document.getElementById("update_all")?.addEventListener('click', function() {
  const title_msg = "{'Are you sure you want to update all extensions?'|@translate}";
  if (window.confirm(title_msg)) {
    updateAll();
  }
});
{/footer_script}

{if $isWebmaster == 1}

<div class="autoupdate_bar">
<div class="head-button-1 icon-ok-circled" id="update_all">{'Update All'|@translate}</div>
<div class="head-button-2 icon-block" id="ignore_all" onClick="ignoreAll(); return false;">{'Ignore All'|@translate}</div>
<div class="head-button-2 icon-ccw" id="reset_ignore" onClick="resetIgnored(); return false;" {if !$SHOW_RESET}style="display:none;"{/if}>{'Reset ignored updates'|@translate}</div>
</div>
<div class="autoupdate_bar" style="display:none;">
{'Please wait...'|@translate}<br><img src="admin/themes/default/images/ajax-loader-bar.gif">
</div>

<p id="up_to_date" style="display:none; text-align:left; margin-left:20px;">{'All %s are up to date.'|@sprintf:$EXT_TYPE|@translate}</p>

{foreach from=$UPDATES_EXTENSION key=type item=updates}
  {if not empty($updates)}
    <fieldset id="{$type}" class="pluginContainer pluginUpdateContainer line-form" data-type="{$type}">
    <legend>
    {if $type=='plugins'}
      <span class="icon-puzzle icon-green"></span>{'Plugins'|@translate}
    {elseif $type=='themes'}
      <span class="icon-brush icon-blue"></span>{'Themes'|@translate}
    {elseif $type=='languages'}
      <span class="icon-language icon-purple"></span>{'Languages'|@translate}
    {/if}
    </legend>
    
    {foreach from=$updates item=extension}
      <div class="pluginBox pluginMiniBox" id="{$type}_{$extension.EXT_ID}" {if $extension.IGNORED}data-ignored="true" style="display:none;"{/if}>
        <div class="pluginContent">
          <div class="pluginName">
            {$extension.EXT_NAME}
          </div>
          <div class="pluginDesc" id="desc_{$extension.ID}">
            <span class="plugin-version plugin-version-old icon-flow-branch" title="{"Current Version"|@translate}">{$extension.CURRENT_VERSION}</span> <i class="icon-right"></i> <span class="plugin-version icon-flow-branch" title="{"New Version"|@translate}">{$extension.NEW_VERSION}</span>
            <div class="plugin-revision-info"><span>{$extension.REV_DESC}</span></div>
            <a href='{$extension.EXT_URL}' target="_blank" class="plugin-update-link icon-info-circled-1">{'More information'|@translate}</a>
          </div>
          <div class="pluginActions">
            <a href="#" onClick="updateExtension('{$type}', '{$extension.EXT_ID}', {$extension.REVISION_ID});" class="updateExtension pluginActionLevel1"> <i class="icon-ok-circled"></i> {'Install'|@translate}</a>
            <a href="{$extension.URL_DOWNLOAD}" class="pluginActionLevel2"> <i class="icon-download"></i> {'Download'|@translate}</a>
            <a href="#" onClick="ignoreExtension('{$type}', '{$extension.EXT_ID}'); return false;" class="ignoreExtension pluginActionLevel2"><i class="icon-block"></i>{'Ignore this update'|@translate}</a>
          </div>
        </div>
      </div>
    {/foreach}
    </fieldset>
  {/if}
{/foreach}
{/if}