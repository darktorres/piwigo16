{footer_script}<script>
window.vt_strings = {
  unexpected_end: '{'Server process ended unexpectedly. Check PHP error log for details.'|translate|escape:'javascript'}',
  connection_lost: '{'The connection to the server was lost.'|translate|escape:'javascript'}',
  try_again: '{'Try again'|translate|escape:'javascript'}',
  aborted: '{'Aborted'|translate|escape:'javascript'}',
  aborted_message: '{'Aborted. Any thumbnails already generated are saved.'|translate|escape:'javascript'}',
  back: '{'Back'|translate|escape:'javascript'}',
  generating: '{'Generating video thumbnails'|translate|escape:'javascript'}',
  extracting: '{'Extracting frames with FFmpeg'|translate|escape:'javascript'}',
  generated: '{'generated'|translate|escape:'javascript'}',
  skipped: '{'skipped'|translate|escape:'javascript'}',
  done: '{'Done'|translate|escape:'javascript'}',
  file_not_found: '{'File not found on disk'|translate|escape:'javascript'}',
  ffmpeg_output: '{'FFmpeg output'|translate|escape:'javascript'}',
  ffmpeg_no_output: '{'FFmpeg produced no output'|translate|escape:'javascript'}',
  thumbnails_generated: '{'thumbnails generated'|translate|escape:'javascript'}',
  skipped_reason: '{'skipped (file not found or FFmpeg unavailable)'|translate|escape:'javascript'}',
  error: '{'Error'|translate|escape:'javascript'}',
  run_again: '{'Run again'|translate|escape:'javascript'}'
};
</script>{/footer_script}

{if $vite_generate_video_thumbnails}
<script type="module" src="admin/themes/default/js/dist/{$vite_generate_video_thumbnails}"></script>
{/if}

<style>
.sync-phase {
  display: flex; align-items: center; flex-wrap: wrap;
  padding: 10px 0; gap: 8px;
  border-bottom: 1px solid rgba(255,255,255,0.1);
}
.sync-phase:last-child { border-bottom: none; }
.sync-phase .phase-status { width: 22px; text-align: center; flex-shrink: 0; color: #6bc46d; font-weight: bold; font-size: 16px; }
.sync-phase.running .phase-label { font-weight: 600; color: #fff; }
.sync-phase .phase-detail { color: #aaa; font-size: 13px; }
.sync-phase .phase-time { margin-left: auto; color: #999; font-size: 12px; }
.sync-progress-bar { width: 100%; display: flex; align-items: center; gap: 10px; margin-top: 2px; padding-left: 30px; }
.progress-track { flex: 1; height: 16px; background: #4a4a4a; border-radius: 8px; overflow: hidden; }
.progress-fill { height: 100%; background: linear-gradient(90deg, #0073aa, #00a0d2); border-radius: 8px; transition: width 0.3s ease; width: 0; }
.progress-text { font-size: 12px; color: #bbb; white-space: nowrap; }
.sync-elapsed { margin-top: 12px; color: #aaa; font-size: 13px; }
@keyframes spin { to { transform: rotate(360deg); } }
.spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #555; border-top-color: #00a0d2; border-radius: 50%; animation: spin 0.8s linear infinite; }
.sync-substep { display: flex; align-items: center; flex-wrap: wrap; padding: 5px 0; padding-left: 32px; gap: 8px; font-size: 13px; border-bottom: 1px solid rgba(255,255,255,0.05); }
.sync-substep:last-child { border-bottom: none; }
.sync-substep .substep-status { width: 18px; text-align: center; flex-shrink: 0; color: #6bc46d; font-weight: bold; font-size: 13px; }
.sync-substep .substep-status .spinner { width: 12px; height: 12px; }
.sync-substep.running .substep-label { color: #ddd; }
.sync-substep .substep-label { color: #999; }
.sync-substep .substep-detail { color: #ccc; font-size: 12px; }
.vt-current-file { min-height: 1.4em; font-size: 11px; color: #bbb; padding-left: 56px; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sync-substep .substep-time { margin-left: auto; color: #777; font-size: 11px; }
.sync-substep .sync-progress-bar { padding-left: 26px; }
.sync-phase.aborted .phase-status, .sync-substep.aborted .substep-status { color: #e25b5b; }
#vtControls { margin-left: 20px; }
.syncControlBtn { padding: 4px 14px; font-size: 12px; cursor: pointer; margin-right: 6px; border: none; border-radius: 3px; }
.syncAbortBtn { background: #b32d2e; color: #fff; }
.syncAbortBtn:hover { background: #9b2324; }
#vtResults ul { margin: 5px 0 5px 20px; }
#vtSkippedList { margin-top: 10px; max-height: 200px; overflow-y: auto; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 6px; }
.vt-skip-entry { font-size: 12px; color: #e09050; padding: 3px 0; }
.vt-skip-ffmpeg-output { font-size: 11px; color: #ccc; background: #2a2a2a; border: 1px solid #444; border-radius: 3px; padding: 6px 8px; margin: 3px 0 6px; white-space: pre-wrap; word-break: break-all; max-height: 120px; overflow-y: auto; }
</style>

<form id="vtForm" method="post" action="{$U_ACTION}">
  <input type="hidden" name="pwg_token" value="{$pwg_token}">
  {if $PENDING_COUNT > 0}
    <p style="color:#ddd">{sprintf('Found %d video(s) without a thumbnail. FFmpeg will extract a frame from each one.'|translate, $PENDING_COUNT)}</p>
  {else}
    <p style="color:#ddd">{'All videos already have thumbnails.'|translate}</p>
  {/if}
  <p class="bottomButtons">
    <button type="submit" class="icon-film buttonGradient"{if $PENDING_COUNT == 0} disabled{/if}>
      {'Generate video thumbnails'|translate}
    </button>
  </p>
</form>

<div id="vtProgress" style="display:none">
  <fieldset>
    <legend><span class="icon-film icon-blue"></span> <span id="vtTitle">{'Generating video thumbnails&hellip;'|translate}</span></legend>
    <div id="vtPhases"></div>
    <p class="sync-elapsed">
      {'Elapsed'|translate}: <strong id="vtElapsed">0.0s</strong>
      <span id="vtControls" style="display:none">
        <button type="button" id="vtAbort" class="buttonGradient syncControlBtn syncAbortBtn">{'Abort'|translate}</button>
      </span>
    </p>
    <div id="vtSkippedList" style="display:none"></div>
  </fieldset>
  <div id="vtResults" style="display:none"></div>
</div>
