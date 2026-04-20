{combine_css path='admin/themes/default/css/pages/generate_video_thumbnails.css' order=-10}
<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

{if $vite_generate_video_thumbnails}
<script type="module" src="admin/themes/default/js/dist/{$vite_generate_video_thumbnails}"></script>
{/if}

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
