{combine_css path='admin/themes/default/css/pages/site_update.css' order=-10}
{if $vite_site_update}
<script id="pwg-page-data" type="application/json">{$page_data_json}</script>
<script type="module" src="admin/themes/default/js/dist/{$vite_site_update}"></script>
{/if}

<div class="selectedAlbum site-url-path">
  <span class="icon-folder-open selectedAlbum-first">{$SITE_URL}</span>
</div>

<div id="syncProgress" style="display:none">
  <fieldset>
    <legend><span class="icon-exchange icon-blue"></span> <span id="syncTitle">Synchronization in progress&hellip;</span></legend>
    <div id="syncPhases"></div>
    <p class="sync-elapsed">
      Elapsed: <strong id="syncElapsed">0.0s</strong>
      <span id="syncControls">
        <button type="button" id="syncPause" class="buttonGradient syncControlBtn">Pause</button>
        <button type="button" id="syncAbort" class="buttonGradient syncControlBtn syncAbortBtn">Abort</button>
      </span>
    </p>
  </fieldset>
  <div id="syncResults" style="display:none"></div>
</div>

{if isset($update_result)}
  <h3>{$L_RESULT_UPDATE}</h3>
  <ul>
    <li class="update_summary_new">{$update_result.NB_NEW_CATEGORIES} {'albums added in the database'|translate}</li>
    <li class="update_summary_new">{$update_result.NB_NEW_ELEMENTS} {'photos added in the database'|translate}</li>
    <li class="update_summary_del">{$update_result.NB_DEL_CATEGORIES} {'albums deleted in the database'|translate}</li>
    <li class="update_summary_del">{$update_result.NB_DEL_ELEMENTS} {'photos deleted from the database'|translate}</li>
    <li>{$update_result.NB_UPD_ELEMENTS} {'photos updated in the database'|translate}</li>
    <li class="update_summary_err">{$update_result.NB_ERRORS} {'errors during synchronization'|translate}</li>
  </ul>
{/if}

{if isset($metadata_result)}
  <h3>{$L_RESULT_METADATA}</h3>
  <ul>
    <li>{$metadata_result.NB_ELEMENTS_DONE} {'photos information synchronized with files metadata'|translate}</li>
    <li>{$metadata_result.NB_ELEMENTS_CANDIDATES} {'photos candidates for metadata synchronization'|translate}</li>
    <li>{'Used metadata'|translate} : {$METADATA_LIST}</li>
  </ul>
{/if}

{if not empty($sync_errors)}
  <h3>{'Error list'|translate}</h3>
  <div class="errors">
    <ul>
      {foreach $sync_errors as $error}
        <li>[{$error.ELEMENT}] {$error.LABEL}</li>
      {/foreach}
    </ul>
  </div>
  <h3>{'Errors caption'|translate}</h3>
  <ul>
    {foreach $sync_error_captions as $caption}
      <li><strong>{$caption.TYPE}</strong>: {$caption.LABEL}</li>
    {/foreach}
  </ul>
{/if}

{if isset($introduction)}
  <form action="" method="post" id="update">

    <fieldset id="syncFiles">
      <legend><span class="icon-docs icon-blue"></span>{'synchronize files structure with database'|translate}</legend>
      <ul>
        <li><label><input type="radio" name="sync" value="" {if empty($introduction.sync)}checked="checked" {/if}>
            {'nothing'|translate}</label></li>
        <li><label><input type="radio" name="sync" value="dirs" {if 'dirs'==$introduction.sync}checked="checked" {/if}>
            {'only directories'|translate}</label></li>

        <li><label><input type="radio" name="sync" value="files" {if 'files'==$introduction.sync}checked="checked" {/if}>
            {'directories + files'|translate}</label>
          <ul style="display:none;padding-left:3em">
            <li><label><input type="checkbox" name="add_to_caddie" value="1"
                  {if $introduction.add_to_caddie}checked="checked" {/if}> {'add new photos to caddie'|translate}</label>
            </li>
            <li><label>{'Who can see these photos?'|translate} <select
                  name="privacy_level">{html_options options=$introduction.privacy_level_options selected=$introduction.privacy_level_selected}</select></label>
            </li>
          </ul>
        </li>
      </ul>
    </fieldset>

    <fieldset id="syncMetadata">
      <legend><span
          class="icon-hdd icon-red"></span>{'synchronize files metadata with database photos information'|translate}
      </legend>
      <label><input type="checkbox" name="sync_meta" {if $introduction.sync_meta}checked="checked" {/if}>
        {'Synchronize metadata'|translate} ({$METADATA_LIST})</label>
      <ul style="padding-left:3em">
        <li>
          <label><input type="checkbox" name="meta_all" {if $introduction.meta_all}checked="checked" {/if}>
            {'even already synchronized photos'|translate}</label>
        </li>
        <li>
          <label><input type="checkbox" name="meta_empty_overrides"
              {if $introduction.meta_empty_overrides}checked="checked" {/if}>
            {'overrides existing values with empty ones'|translate}</label>
        </li>
      </ul>
    </fieldset>

    <fieldset id="syncSimulate">
      <legend><span class="icon-chart-bar icon-green"></span>{'Simulation'|translate}</legend>
      <ul>
        <li><label><input type="checkbox" name="simulate" value="1" checked="checked">
            {'only perform a simulation (no change in database will be made)'|translate}</label></li>
      </ul>
    </fieldset>

    <p class="bottomButtons syncBtn">
      <button class="icon-exchange buttonGradient" type="submit" value="" name="submit"> {'Synchronize'|translate}
      </button>
    </p>
  </form>

  <fieldset id="syncVideoThumbnails">
    <legend><span class="icon-film icon-purple"></span>{'Video thumbnails'|translate}</legend>
    <p style="text-align:left">
      <a href="admin.php?page=generate_video_thumbnails"
        class="icon-film buttonGradient">{'Generate video thumbnails'|translate}</a>
    </p>
  </fieldset>

  <fieldset id="syncThumbnails">
    <legend><span class="icon-picture icon-purple"></span>{'Thumbnails'|translate}</legend>
    <p style="text-align:left">
      <a href="admin.php?page=generate_thumbnails"
        class="icon-picture buttonGradient">{'Generate thumbnails'|translate}</a>
    </p>
  </fieldset>
{/if}{*isset $introduction*}