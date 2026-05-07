<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

{combine_script id='common' load='footer' path='themes/admin/_base/js/common.js'}
{combine_script id='updates_ext' load='footer' require='common' path='themes/admin/_base/js/updates_ext.js'}

{if $isWebmaster == 1}

<div class="autoupdate_bar">
<div class="head-button-1 icon-ok-circled" id="update_all">{'Update All'|@translate}</div>
<div class="head-button-2 icon-block" id="ignore_all">{'Ignore All'|@translate}</div>
<div class="head-button-2 icon-ccw" id="reset_ignore" {if !$SHOW_RESET}hidden{/if}>{'Reset ignored updates'|@translate}</div>
</div>
<div class="autoupdate_bar" hidden>
{'Please wait...'|@translate}<br><img src="themes/admin/_base/images/ajax-loader-bar.gif">
</div>

<p id="up_to_date" hidden>{'All %s are up to date.'|@sprintf:$EXT_TYPE|@translate}</p>

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
      <div class="pluginBox pluginMiniBox" id="{$type}_{$extension.EXT_ID}" {if $extension.IGNORED}data-ignored="true" hidden{/if}>
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
            <a href="#" data-ext-type="{$type}" data-ext-id="{$extension.EXT_ID}" data-revision-id="{$extension.REVISION_ID}" class="updateExtension pluginActionLevel1"> <i class="icon-ok-circled"></i> {'Install'|@translate}</a>
            <a href="{$extension.URL_DOWNLOAD}" class="pluginActionLevel2"> <i class="icon-download"></i> {'Download'|@translate}</a>
            <a href="#" data-ext-type="{$type}" data-ext-id="{$extension.EXT_ID}" class="ignoreExtension pluginActionLevel2"><i class="icon-block"></i>{'Ignore this update'|@translate}</a>
          </div>
        </div>
      </div>
    {/foreach}
    </fieldset>
  {/if}
{/foreach}
{/if}
