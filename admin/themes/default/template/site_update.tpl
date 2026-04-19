{if $vite_site_update}
<script id="pwg-page-data" type="application/json">{$page_data_json}</script>
<script type="module" src="admin/themes/default/js/dist/{$vite_site_update}"></script>
{/if}

<style>
.sync-phase {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  padding: 10px 0;
  gap: 8px;
  border-bottom: 1px solid rgba(255,255,255,0.1);
}
.sync-phase:last-child { border-bottom: none; }
.sync-phase .phase-status {
  width: 22px;
  text-align: center;
  flex-shrink: 0;
  color: #6bc46d;
  font-weight: bold;
  font-size: 16px;
}
.sync-phase.running .phase-label { font-weight: 600; color: #fff; }
.sync-phase .phase-detail { color: #aaa; font-size: 13px; }
.sync-phase .phase-time { margin-left: auto; color: #999; font-size: 12px; }
.sync-progress-bar {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 2px;
  padding-left: 30px;
}
.progress-track {
  flex: 1;
  height: 16px;
  background: #4a4a4a;
  border-radius: 8px;
  overflow: hidden;
}
.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #0073aa, #00a0d2);
  border-radius: 8px;
  transition: width 0.3s ease;
  width: 0;
}
.progress-text { font-size: 12px; color: #bbb; white-space: nowrap; }
.sync-elapsed { margin-top: 12px; color: #aaa; font-size: 13px; }
@keyframes spin { to { transform: rotate(360deg); } }
.spinner {
  display: inline-block;
  width: 14px;
  height: 14px;
  border: 2px solid #555;
  border-top-color: #00a0d2;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
.sync-substep {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  padding: 5px 0;
  padding-left: 32px;
  gap: 8px;
  font-size: 13px;
  border-bottom: 1px solid rgba(255,255,255,0.05);
}
.sync-substep:last-child { border-bottom: none; }
.sync-substep .substep-status {
  width: 18px;
  text-align: center;
  flex-shrink: 0;
  color: #6bc46d;
  font-weight: bold;
  font-size: 13px;
}
.sync-substep .substep-status .spinner { width: 12px; height: 12px; }
.sync-substep.running .substep-label { color: #ddd; }
.sync-substep .substep-label { color: #999; }
.sync-substep .substep-detail { color: #888; font-size: 12px; }
.sync-substep .substep-time { margin-left: auto; color: #777; font-size: 11px; }
.sync-substep .sync-progress-bar { padding-left: 26px; }
.sync-substep.aborted .substep-status { color: #e25b5b; }
#syncResults h4 { margin: 15px 0 5px; }
#syncResults ul { margin: 0 0 5px 20px; }
#syncControls { margin-left: 20px; }
.syncControlBtn {
  padding: 4px 14px;
  font-size: 12px;
  cursor: pointer;
  margin-right: 6px;
  border: none;
  border-radius: 3px;
}
.syncAbortBtn {
  background: #b32d2e;
  color: #fff;
}
.syncAbortBtn:hover { background: #9b2324; }
.syncPausedLabel {
  color: #f0c33c;
  font-weight: 600;
  margin-left: 10px;
}
.sync-phase.aborted .phase-status { color: #e25b5b; }
</style>

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
{/if}{*isset $introduction*}