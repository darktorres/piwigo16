{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}

<script id="pwg-page-data" type="application/json">
{
  "confirm_msg": "{"Yes, I am sure"|translate|escape:'html'}",
  "cancel_msg": "{"No, I have changed my mind"|translate|escape:'html'}",
  "no_time_elapsed": "{"right now"|translate|escape:'html'}",
  "unit_MB": "{"%s MB"|translate|escape:'html'}",
  "pwg_token": "{$pwg_token|escape:'html'}"
}
</script>

{footer_script}<script>
  window.str_gallery_tip = '{"A locked gallery is only visible to administrators"|translate|escape:'javascript'}';
  {if (isset($U_MAINT_LOCK_GALLERY))}
    window.str_lock_unlock_title = '{"Are you sure you want to lock the gallery?"|translate|escape:'javascript'}';
  {else}
    window.str_lock_unlock_title = '{"Are you sure you want to unlock the gallery?"|translate|escape:'javascript'}';
  {/if}
  window.str_purge_detail = '{"Purge history detail"|translate|escape:'javascript'}';
  window.str_purge_summary = '{"Purge history summary"|translate|escape:'javascript'}';
  window.str_purge_search = '{"Purge search history"|translate|escape:'javascript'}';
  window.str_delete_all_sizes = '{"Are you sure you want to delete all sizes?"|translate|escape:'javascript'}';
</script>{/footer_script}

{if $vite_maintenance}
<script type="module" src="/admin/themes/default/js/dist/{$vite_maintenance}"></script>
{/if}

{if $isWebmaster == 1}

  <fieldset class="">
    <legend><span class="icon-globe icon-blue"></span>{'Global Gallery Actions'|translate}</legend>
    <div style="display:flex;flex-wrap: wrap;">
      {if (isset($U_MAINT_LOCK_GALLERY))}
        <a href="{$U_MAINT_LOCK_GALLERY}"
          class="lock-gallery-button icon-lock maintenance-action">{'Lock gallery'|translate}</a>
      {else}
        <a href="{$U_MAINT_UNLOCK_GALLERY}"
          class="lock-gallery-button icon-lock maintenance-action">{'Unlock gallery'|translate}</a>
      {/if}
      <a href="admin.php?page=generate_video_thumbnails"
        class="icon-film maintenance-action">{'Generate video thumbnails'|translate}</a>
      <a href="{$U_MAINT_CATEGORIES}"
        class="icon-folder-open maintenance-action">{'Update albums information'|translate}</a>
      <a href="{$U_MAINT_IMAGES}"
        class="icon-info-circled-1 maintenance-action">{'Update photos information'|translate}</a>
      <a href="{$U_MAINT_DATABASE}"
        class="icon-database maintenance-action">{'Repair and optimize database'|translate}</a>
      <a href="{$U_MAINT_C13Y}" class="icon-ok maintenance-action">{'Reinitialize check integrity'|translate}</a>
    </div>
  </fieldset>
  <fieldset class="">
    <legend><span class="icon-trash-1 icon-green"></span>{'Purge Actions'|translate}</legend>
    <div style="display:flex;flex-wrap: wrap;">
      <a href="{$U_MAINT_USER_CACHE}" class="icon-user-1 maintenance-action">{'Purge user cache'|translate}</a>
      <a href="{$U_MAINT_ORPHAN_TAGS}" class="icon-tags maintenance-action">{'Delete orphan tags'|translate}</a>
      <a href="{$U_MAINT_HISTORY_DETAIL}"
        class="icon-back-in-time maintenance-action purge-history-detail-button">{'Purge history detail'|translate}</a>
      <a href="{$U_MAINT_HISTORY_SUMMARY}"
        class="icon-back-in-time maintenance-action purge-history-summary-button">{'Purge history summary'|translate}</a>
      <a href="{$U_MAINT_SESSIONS}" class="icon-th-list maintenance-action">{'Purge sessions'|translate}</a>
      <a href="{$U_MAINT_FEEDS}"
        class="icon-bell maintenance-action">{'Purge never used notification feeds'|translate}</a>
      <a href="{$U_MAINT_SEARCH}"
        class="icon-search maintenance-action purge-search-history-button">{'Purge search history'|translate}</a>
    </div>
  </fieldset>

  {if isset($advanced_features) and !(count($advanced_features) < 1)}
    <fieldset class="">
      <legend><span class="icon-puzzle icon-purple"></span>{'Advanced features'|translate}</legend>
      <div style="display:flex;flex-wrap: wrap;">
        {foreach $advanced_features as $feature}
          <a href="{$feature.URL}" class="{$feature.ICON} maintenance-action">{$feature.CAPTION}</a>
        {/foreach}
      </div>
    </fieldset>
  {/if}

  <fieldset class="">
    <legend><span class="icon-trash-1 icon-red"></span>{'Purge Cache'|translate}</legend>

    <div class="template-purge">
      <div class="cache-infos">
        <span class="cache-size-text">{'Cache size'|translate}</span>
        <span class="cache-size-value">
          {if isset($cache_sizes)}
            {"%s MB"|translate:{round($cache_sizes[0]['value']/1024/1024, 2)}}
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
      </div>
      <a href="{$U_MAINT_COMPILED_TEMPLATES}"
        class="icon-file-code maintenance-action">{'Purge compiled templates'|translate}
        <span class="multiple-compiledTemplate-sizes">
          {if isset($cache_sizes)}
            {"%s MB"|translate:{round($cache_sizes[2]['value']/1024/1024, 2)}}
          {else}
            {'N/A'|translate}
          {/if}
        </span>
      </a>
    </div>

    <div class="delete-size-checks">
      <span id="label-delete-size-checkbox">{'Delete multiple size images'|translate}
        <span class="multiple-pictures-sizes">
          {if isset($cache_sizes)}
            {"%s MB"|translate:{round($cache_sizes[1]['value']['all']/1024/1024, 2)}}
          {else}
            {'N/A'|translate}
          {/if}
        </span>
      </span>
      <div class="delete-check-container">
        {foreach $purge_derivatives as $name => $url}
          <div class="delete-size-check"
            title="{if isset($cache_sizes)}{"%s MB"|translate:{round($cache_sizes[1]['value'][$url]/1024/1024, 2)}}{else}{'N/A'|translate}{/if}"
            data-selected="0" name="{$url}">
            <span class="select-checkbox"><i class="icon-ok" style="margin-left:8px"></i></span>
            <span class="picture-deletion-size" style="font-size:14px;margin-left:5px;padding-top:2px;">{$name}</span>
          </div>
        {/foreach}
      </div>
    </div>

    <a class="icon-ok delete-sizes">{'Delete these sizes'|translate}</a>
  </fieldset>

{/if}
<style>
  #label-delete-size-checkbox {
    font-weight: bold;
    white-space: nowrap;
  }

  .maintenance-action:hover {
    color: #ff7700;
    text-decoration: none;
  }

  .maintenance-action {
    border: solid 1px;
    padding: 8px 10px;
    margin-right: 20px;
    margin-bottom: 20px;
  }

  .maintenance-action.icon-th-list {
    font-size: 13px;
  }

  .delete-size-checks {
    display: flex;
    text-align: left;
    margin-bottom: 5px;

    flex-direction: column;
  }

  .delete-check-container {
    display: flex;
    flex-wrap: wrap;
    margin-top: 15px;
  }

  .delete-size-check {
    margin-right: 15px;
    margin-bottom: 10px;
    display: flex;
    cursor: pointer
  }

  .select-checkbox {
    display: inline-block;
  }

  .delete-sizes {
    display: block;
    width: max-content;
    text-align: left;
  }

  .delete-sizes {
    cursor: pointer;
    padding: 8px 10px;
    font-weight: bold;
    background-color: #ffa744;
    color: #3c3c3c;
  }

  .delete-sizes:hover {
    background-color: #ff7700;
    color: #3c3c3c;
    text-decoration: none;
  }

  .rotate-anim {
    animation: spin 4s linear infinite;
  }

  @keyframes spin {
    100% {
      -webkit-transform: rotate(360deg);
      transform: rotate(360deg);
    }
  }
</style>