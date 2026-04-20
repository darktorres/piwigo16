{combine_css path='admin/themes/default/css/pages/maintenance.css' order=-10}
{combine_css path="admin/themes/default/fontello/css/animation.css" order=10}
{* order 10 is required, see issue 1080 *}
<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

{if $vite_maintenance}
<script type="module" src="admin/themes/default/js/dist/{$vite_maintenance}"></script>
{/if}
{if $vite_maintenance_env}
<script type="module" src="admin/themes/default/js/dist/{$vite_maintenance_env}"></script>
{/if}

<fieldset id="environment">
  <legend><span class="icon-television icon-red"></span> {'Environment'|translate}</legend>
  <ul style="font-weight:bold">
    <li><a href="{$PHPWG_URL}" class="externalLink">Piwigo</a> {$PWG_VERSION} <a href="{$U_CHECK_UPGRADE}&tab=env"
        class="icon-arrows-cw">{'Check for upgrade'|translate}</a></li>
    {if isset($INSTALLED_ON)}
      <li>{'Installed on %s, %s'|translate:$INSTALLED_ON:$INSTALLED_SINCE}</li>
    {/if}
    <li>{'Operating system'|translate}: {$OS}</li>
    <li>PHP: {$PHP_VERSION} (<a href="{$U_PHPINFO}" class="externalLink">{'Show info'|translate}</a>) [{$PHP_DATATIME}]
    </li>
    <li>{$DB_ENGINE}: {$DB_VERSION} [{$DB_DATATIME}]</li>
    {if isset($GRAPHICS_LIBRARY)}
      <li>{'Graphics Library'|translate}: {$GRAPHICS_LIBRARY}</li>
    {/if}
    <li>
      <span class="cache-size-text">{'Cache size'|translate}</span>
      <span class="cache-size-value">
        {if isset($cache_sizes)}
          {round($cache_sizes[0]['value']/1024/1024, 2)} Mo
        {else}
          {'N/A'|translate}
        {/if}
      </span>
      <span
        class="cache-lastCalculated-text">{if $time_elapsed_since_last_calc}&ThickSpace;{'calculated'|translate}{/if}</span>
      <span class="cache-lastCalculated-value">{if $time_elapsed_since_last_calc} {$time_elapsed_since_last_calc}
        {else}
        &ThickSpace;{"never calculated"|translate} {/if}</span>
      <a class="refresh-cache-size"><span class="refresh-icon icon-arrows-cw"></span>{'Refresh'|translate}</a>
    </li>
  </ul>
</fieldset>

<fieldset id="pluginList">
  <legend><span class="icon-puzzle icon-green"></span> {'Activated plugin list'|translate} <span
      class="badge-number"></span></legend>
  <ul style="font-weight:bold">
    <i class="icon-spin6 animate-spin"></i>
  </ul>
</fieldset>

