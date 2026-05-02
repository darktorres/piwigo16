{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}
<script id="pwg-page-data" type="application/json">{$page_data_json}</script>
{combine_script id='ajax' load='footer' require='common' path='admin/themes/default/js/maintenance.js'}

{if $isWebmaster == 1}

<fieldset class="">
  <legend><span class="icon-globe icon-blue"></span>{'Global Gallery Actions'|translate}</legend>
  <div style="display:flex;flex-wrap: wrap;">
{if (isset($U_MAINT_LOCK_GALLERY))}
    <a href="{$U_MAINT_LOCK_GALLERY}" class="lock-gallery-button {$maint_actions['lock_gallery']['icon']} maintenance-action">{$maint_actions['lock_gallery']['label']}</a>
{else}
    <a href="{$U_MAINT_UNLOCK_GALLERY}" class="lock-gallery-button {$maint_actions['unlock_gallery']['icon']} maintenance-action">{$maint_actions['unlock_gallery']['label']}</a>
{/if}
    <a href="{$U_MAINT_CATEGORIES}" class="{$maint_actions['categories']['icon']} maintenance-action">{$maint_actions['categories']['label']}</a>
    <a href="{$U_MAINT_IMAGES}" class="{$maint_actions['images']['icon']} maintenance-action">{$maint_actions['images']['label']}</a>
    <a href="{$U_MAINT_DATABASE}" class="{$maint_actions['database']['icon']} maintenance-action">{$maint_actions['database']['label']}</a>
    <a href="{$U_MAINT_C13Y}" class="{$maint_actions['c13y']['icon']} maintenance-action">{$maint_actions['c13y']['label']}</a>
{if (isset($U_EMPTY_LOUNGE))}
    <a href="{$U_EMPTY_LOUNGE}" class="{$maint_actions['empty_lounge']['icon']} maintenance-action tiptip" title="{'There is currently %d photos in the lounge (upload buffer)'|translate:$LOUNGE_COUNTER}">{$maint_actions['empty_lounge']['label']}<span class="multiple-pictures-sizes">{$LOUNGE_COUNTER}</span></a>
{/if}
  </div>
</fieldset>
<fieldset class="">
  <legend><span class="icon-trash-1 icon-green"></span>{'Purge Actions'|@translate}</legend>
  <div style="display:flex;flex-wrap: wrap;">
    <a href="{$U_MAINT_USER_CACHE}" class="{$maint_actions['user_cache']['icon']} maintenance-action">{$maint_actions['user_cache']['label']}</a>
    <a href="{$U_MAINT_ORPHAN_TAGS}" class="{$maint_actions['delete_orphan_tags']['icon']} maintenance-action">{$maint_actions['delete_orphan_tags']['label']}</a>
    <a href="{$U_MAINT_HISTORY_DETAIL}" class="{$maint_actions['history_detail']['icon']} maintenance-action purge-history-detail-button">{$maint_actions['history_detail']['label']}</a>
    <a href="{$U_MAINT_HISTORY_SUMMARY}" class="{$maint_actions['history_summary']['icon']} maintenance-action purge-history-summary-button">{$maint_actions['history_summary']['label']}</a>
    <a href="{$U_MAINT_SESSIONS}" class="{$maint_actions['sessions']['icon']} maintenance-action">{$maint_actions['sessions']['label']}</a>
    <a href="{$U_MAINT_FEEDS}" class="{$maint_actions['feeds']['icon']} maintenance-action">{$maint_actions['feeds']['label']}</a>
    <a href="{$U_MAINT_SEARCH}" class="{$maint_actions['search']['icon']} maintenance-action purge-search-history-button">{$maint_actions['search']['label']}</a>
  </div>
</fieldset>

{if isset($advanced_features) and !(count($advanced_features) < 1)}
  <fieldset class="">
  <legend><span class="icon-puzzle icon-purple"></span>{'Advanced features'|@translate}</legend>
  <div style="display:flex;flex-wrap: wrap;">
  {foreach from=$advanced_features item=feature key=key name=name}
    <a href="{$feature.URL}" class="{$feature.ICON} maintenance-action">{$feature.CAPTION}</a>
  {/foreach}
  </div>
</fieldset>
{/if}

<fieldset class="">
  <legend><span class="icon-trash-1 icon-red"></span>{'Purge Cache'|@translate}</legend>

  <div class="template-purge">
    <div class="cache-infos">
        <span class="cache-size-text">{'Cache size'|@translate}</span>
        <span class="cache-size-value">
{if isset($cache_sizes)}
          {"%s MB"|@translate:{round($cache_sizes[0]['value']/1024/1024, 2)}}
{else}
          {'N/A'|translate}
{/if}
        </span>
        <span class="cache-lastCalculated-text">{if $time_elapsed_since_last_calc}&ThickSpace;{'calculated'|@translate}{/if}</span>
        <span class="cache-lastCalculated-value">{if $time_elapsed_since_last_calc} {$time_elapsed_since_last_calc} {else} &ThickSpace;{"never calculated"|@translate} {/if}</span>
        <a class="refresh-cache-size"><span class="refresh-icon icon-arrows-cw"></span>{'Refresh'|@translate}</a>
    </div>
    <a href="{$U_MAINT_COMPILED_TEMPLATES}" class="{$maint_actions['compiled-templates']['icon']} maintenance-action">{$maint_actions['compiled-templates']['label']} 
      <span class="multiple-compiledTemplate-sizes">
{if isset($cache_sizes)}
        {"%s MB"|@translate:{round($cache_sizes[2]['value']/1024/1024, 2)}}  
{else}
        {'N/A'|translate}
{/if}
      </span>
    </a>
  </div>

  <div class="delete-size-checks">
    <span id="label-delete-size-checkbox">{$maint_actions['derivatives']['label']}
      <span class="multiple-pictures-sizes">
{if isset($cache_sizes)}
        {"%s MB"|@translate:{round($cache_sizes[1]['value']['all']/1024/1024, 2)}}
{else}
        {'N/A'|translate}
{/if}
      </span>
    </span>
    <div class="delete-check-container">
{foreach from=$purge_derivatives key=name item=url name=loop}
      <div class="delete-size-check" title="{if isset($cache_sizes) and isset($cache_sizes[1]['value'][$url])}{"%s MB"|@translate:{round($cache_sizes[1]['value'][$url]/1024/1024, 2)}}{else}{'N/A'|translate}{/if}" data-selected="0" name="{$url}">
          <span class="select-checkbox"><i class="icon-ok" style="margin-left:8px"></i></span>
          <span class="picture-deletion-size" style="font-size:14px;margin-left:5px;padding-top:2px;">{$name}</span>
      </div>
{/foreach}
    </div>
  </div>

  <a class="icon-ok delete-sizes">{'Delete these sizes'|@translate}</a>
</fieldset>

{/if}
{combine_css path="admin/themes/default/css/pages/maintenance-actions.css"}