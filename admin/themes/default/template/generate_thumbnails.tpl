{combine_css path='admin/themes/default/css/pages/generate_thumbnails.css' order=-10}
<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

{if $vite_generate_thumbnails}
<script type="module" src="admin/themes/default/js/dist/{$vite_generate_thumbnails}"></script>
{/if}

<form id="gtForm" method="post" action="{$U_ACTION}">
  <input type="hidden" name="pwg_token" value="{$pwg_token}">
  {if $TOTAL_COUNT > 0}
    <p style="color:#ddd">{sprintf('Found %d image(s). Missing derivatives will be generated.'|translate, $TOTAL_COUNT)}</p>
  {else}
    <p style="color:#ddd">{'No images found.'|translate}</p>
  {/if}
  <p class="bottomButtons">
    <button type="submit" class="icon-picture buttonGradient"{if $TOTAL_COUNT == 0} disabled{/if}>
      {'Generate thumbnails'|translate}
    </button>
  </p>
</form>

<div id="gtProgress" style="display:none">
  <fieldset>
    <legend><span class="icon-picture icon-blue"></span> <span id="gtTitle">{'Generating thumbnails&hellip;'|translate}</span></legend>
    <div id="gtPhases"></div>
    <p class="sync-elapsed">
      {'Elapsed'|translate}: <strong id="gtElapsed">0.0s</strong>
      <span id="gtControls" style="display:none">
        <button type="button" id="gtAbort" class="buttonGradient syncControlBtn syncAbortBtn">{'Abort'|translate}</button>
      </span>
    </p>
  </fieldset>
  <div id="gtResults" style="display:none"></div>
</div>
