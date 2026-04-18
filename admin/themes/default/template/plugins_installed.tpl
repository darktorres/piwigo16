<script id="pwg-page-data" type="application/json">
{
  "pwgToken": "{$PWG_TOKEN|escape:'html'}",
  "incompatibleMsg": "{'WARNING! This plugin does not seem to be compatible with this version of Piwigo.'|translate|escape:'html'}",
  "activateMsg": "\n{'Do you want to activate anyway?'|translate|escape:'html'}",
  "deactivateAllMsg": "{'Deactivate all'|translate|escape:'html'}",
  "nbPlugin": {
    "all": {$count_types_plugins["active"]} + {$count_types_plugins["inactive"]} + {$count_types_plugins["missing"]} + {$count_types_plugins["merged"]},
    "active": {$count_types_plugins["active"]},
    "inactive": {$count_types_plugins["inactive"]},
    "other": {$count_types_plugins["missing"]} + {$count_types_plugins["merged"]}
  },
  "areYouSureMsg": "{'Are you sure?'|translate|escape:'html'}",
  "confirmMsg": "{'Yes, I am sure'|translate|escape:'html'}",
  "cancelMsg": "{'No, I have changed my mind'|translate|escape:'html'}",
  "deletePluginMsg": "{'Are you sure you want to delete the plugin \"%s\"?'|translate|escape:'html'}",
  "deletedPluginMsg": "{'Plugin \"%s\" deleted!'|translate|escape:'html'}",
  "restorePluginMsg": "{'Are you sure you want to restore the plugin \"%s\"?'|translate|escape:'html'}",
  "uninstallPluginMsg": "{'Are you sure you want to uninstall the plugin \"%s\"?'|translate|escape:'html'}",
  "restoreTipMsg": "{'Restore default configuration. You will lose your plugin settings!'|translate|escape:'html'}",
  "pluginAddedStr": "{'Activated'|translate|escape:'html'}",
  "pluginDeactivatedStr": "{'Deactivated'|translate|escape:'html'}",
  "pluginRestoredStr": "{'Restored'|translate|escape:'html'}",
  "pluginActionError": "{'an error happened'|translate|escape:'html'}",
  "notWebmaster": "{'Webmaster status required'|translate|escape:'html'}",
  "nothingFound": "{'No plugins found'|translate|escape:'html'}",
  "xPluginsFound": "{'%s plugins found'|translate|escape:'html'}",
  "pluginFound": "{'%s plugin found'|translate|escape:'html'}",
  "isWebmaster": {$isWebmaster},
  "viewSelector": "{$view_selector|escape:'html'}",
  "strRestoreDef": "{'While restoring this plugin, it will be reset to its original parameters and associated data is going to be reset'|translate|escape:'html'}",
  "showDetails": {if $show_details} true {else} false {/if}
}
</script>

{if $vite_plugins_installated}
<script type="module" src="admin/themes/default/js/dist/{$vite_plugins_installated}"></script>
{/if}

{if isset($plugins)}

  {assign var='field_name' value='null'} {* <!-- 'counter' for fieldset management --> *}
  {counter start=0 assign=i} {* <!-- counter for 'deactivate all' link --> *}

  <div class="pluginTypeFilter">
    <input type="radio" name="p-filter" class="filter" id="seeAll" {if $count_types_plugins["active"] <= 0} checked
      {/if}><label for="seeAll">{'All'|translate}<span class="filter-badge">X</span></label><input type="radio"
      name="p-filter" class="filter" id="seeActive" {if $count_types_plugins["active"] > 0} checked {/if}><label
      class="filterLabel" for="seeActive">{'Activated'|translate}<span class="filter-badge">X</span></label><input
      type="radio" name="p-filter" class="filter" id="seeInactive"><label class="filterLabel"
      for="seeInactive">{'Deactivated'|translate}<span class="filter-badge">X</span></label><input type="radio"
      name="p-filter" class="filter" id="seeOther"><label class="filterLabel" for="seeOther">{'Other'|translate}<span
        class="filter-badge">X</span></label>
  </div>

  <div class="nbPluginsSearch"></div>

  <div class="pluginFilter">
    <span class="icon-search search-icon"></span>
    <span class="icon-cancel search-cancel"></span>
    <input class='search-input' type="text" placeholder="{'Search'|translate}">
  </div>

  <div class="AlbumViewSelector">
    <input type="radio" name="layout" class="switchLayout" id="displayClassic"
      {if $view_selector == 'classic'}checked{/if} /><label for="displayClassic"><span class="icon-pause firstIcon tiptip"
        title="{'Classic View'|translate}"></span></label><input type="radio" name="layout" class="switchLayout"
      id="displayLine" {if $view_selector== 'line'}checked{/if} /><label for="displayLine"><span
        class="icon-th-list tiptip" title="{'Line View'|translate}"></span></label><input type="radio" name="layout"
      class="switchLayout" id="displayCompact" {if $view_selector == 'compact'}checked{/if} /><label
      for="displayCompact"><span class="icon-th-large lastIcon tiptip" title="{'Compact View'|translate}"></span></label>
  </div>

  <div
    class="pluginContainer {if $view_selector == 'classic'} classic-form {elseif $view_selector == 'line'} line-form {elseif $view_selector == 'compact'} compact-form {else} {/if}">

    {foreach $plugins as $plugin}

      {if not empty($plugin.AUTHOR)}
        {if not empty($plugin.AUTHOR_URL)}
          {assign var='author' value="<a href='%s'>%s</a>"|sprintf:$plugin.AUTHOR_URL:$plugin.AUTHOR}
        {else}
          {assign var='author' value='<u>'|cat:$plugin.AUTHOR|cat:'</u>'}
        {/if}
      {/if}

      {if not empty($plugin.VISIT_URL)}
        {assign var='version' value="<a class='externalLink' href='"|cat:$plugin.VISIT_URL|cat:"'>"|cat:$plugin.VERSION|cat:"</a>"}
      {else}
        {assign var='version' value=$plugin.VERSION}
      {/if}

      <div id="{$plugin.ID}" class="pluginBox pluginMiniBox {$plugin.STATE} plugin-{$plugin.STATE}">

        <div class="AddPluginSuccess pluginNotif">
          <label class="icon-ok">
            <span>{'Plugin activated'|translate}</span>
          </label>
        </div>

        <div class="DeactivatePluginSuccess pluginNotif">
          <label class="icon-ok">
            <span>{'Plugin deactivated'|translate}</span>
          </label>
        </div>

        <div class="RestorePluginSuccess pluginNotif">
          <label class="icon-ok">
            <span>{'Plugin deactivated'|translate}</span>
          </label>
        </div>

        <div class="PluginActionError pluginNotif">
          <label class="icon-cancel">
            <span>{'Plugin deactivated'|translate}</span>
          </label>
        </div>

        <div class="pluginContent">
          <div class="PluginOptionsIcons">
            {if $plugin.STATE == 'active' || $plugin.STATE == 'inactive'}
              <a class="icon-ellipsis-v showOptions showInfo"></a>
            {/if}
          </div>
          {if $plugin.STATE == 'active' || $plugin.STATE == 'inactive'}
            <label class="switch">
              <input type="checkbox" id="toggleSelectionMode" {if {$plugin.STATE}==="active" }checked{/if}>
              <span class="slider round"></span>
            </label>
          {/if}

          <div class="pluginActionsSmallIcons">
            {if $plugin.STATE == 'active'}
              {if $plugin.SETTINGS_URL != ''}
                <div class="tiptip" title="{'Settings'|translate}">
                  <a href="{$plugin.SETTINGS_URL}"><span class="icon-cog"></span></a>
                </div>
              {else}
                <div class="tiptip" title="{'N/A'|translate}">
                  <a><i class="icon-cog"></i></a>
                </div>
              {/if}
            {elseif $plugin.STATE == 'inactive'}
              {if $plugin.SETTINGS_URL != ''}
                <div class="tiptip" title="{'Settings'|translate}">
                  <a href="{$plugin.SETTINGS_URL}"><span class="icon-cog"></span></a>
                </div>
              {else}
                <div class="tiptip" title="{'N/A'|translate}">
                  <a><i class="icon-cog"></i></a>
                </div>
              {/if}
            {elseif $plugin.STATE == 'missing'}
              <div class="tiptip" title="{'Uninstall'|translate}">
                <a class="uninstall-plugin-button">{'Uninstall'|translate}</a>
              </div>
            {elseif $plugin.STATE == 'merged' and $CONF_ENABLE_EXTENSIONS_INSTALL}
              <div class="tiptip" title="{'Delete'|translate}">
                <a class="" href="{$plugin.U_ACTION}&amp;action=delete">{'Delete'|translate}</a>
              </div>
            {/if}
          </div>

          <div class="PluginOptionsBlock dropdown">
            <div class="dropdown-option-content"> {if !empty($author)}{'By %s'|translate:$author} |
              {/if}{'Version'|translate} {$version}</div>
            <div class="pluginDescCompact">
              {$plugin.DESC}
            </div>
            <a class="dropdown-option icon-back-in-time plugin-restore separator-top tiptip"
              title="{'While restoring this plugin, it will be reset to its original parameters and associated data is going to be reset'|translate}">{'Restore'|translate}</a>
            {if $CONF_ENABLE_EXTENSIONS_INSTALL}
              <a class="dropdown-option icon-trash delete-plugin-button separator-top">{'Delete'|translate}</a>
            {/if}
          </div>
          <div class="pluginName" data-title="{$plugin.NAME}">
            {$plugin.NAME}
          </div>
          <div class="pluginDesc">
            {$plugin.DESC}
          </div>
          <div class="pluginActions">
            {if $plugin.STATE == 'active'}
              {if $plugin.SETTINGS_URL != ''}
                <a href="{$plugin.SETTINGS_URL}" class="pluginActionLevel1 icon-cog">{'Settings'|translate}</a>
              {else}
                <a class="pluginUnavailableAction icon-cog tiptip" title="{'N/A'|translate}">{'Settings'|translate}</a>
              {/if}
            {elseif $plugin.STATE == 'inactive'}
              {if $plugin.SETTINGS_URL != ''}
                <a href="{$plugin.SETTINGS_URL}" class="pluginUnavailableAction icon-cog">{'Settings'|translate}</a>
              {else}
                <a class="pluginUnavailableAction icon-cog tiptip" title="{'N/A'|translate}">{'Settings'|translate}</a>
              {/if}
            {elseif $plugin.STATE == 'missing'}
              <a class="pluginActionLevel3 uninstall-plugin-button">{'Uninstall'|translate}</a>
            {elseif $plugin.STATE == 'merged' and $CONF_ENABLE_EXTENSIONS_INSTALL}
              <a class="pluginActionLevel3" href="{$plugin.U_ACTION}&amp;action=delete">{'Delete'|translate}</a>
            {/if}
          </div>
        </div>

      </div>
    {/foreach}
  </div>
{/if}