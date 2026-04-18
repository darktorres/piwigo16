{if $vite_updates_ext}
<script type="module" src="admin/themes/default/js/dist/{$vite_updates_ext}"></script>
{/if}

{footer_script}<script>
  window.pwg_token = '{$PWG_TOKEN}';
  window.extType = '{$EXT_TYPE}';
  window.confirmMsg = '{'Are you sure?'|translate|escape:'javascript'}';
  window.errorHead = '{'ERROR'|translate|escape:'javascript'}';
  window.successHead = '{'Update Complete'|translate|escape:'javascript'}';
  window.errorMsg = '{'an error happened'|translate|escape:'javascript'}';
  window.restoreMsg = '{'Reset ignored updates'|translate|escape:'javascript'}';
  window.title_confirm_update_all = "{'Are you sure you want to update all extensions?'|translate}";
  window.str_confirm = '{"Yes, I am sure"|translate}';
  window.str_cancel = "{"No, I have changed my mind"|translate}";
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