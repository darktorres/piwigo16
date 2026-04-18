{if $vite_configuration}
<script type="module" src="admin/themes/default/js/dist/{$vite_configuration}"></script>
{/if}

{footer_script}<script>
  (function() {
    function onWatermarkChange() {
      var wSelect = document.getElementById("wSelect");
      var wImg = document.getElementById("wImg");
      if (!wSelect || !wImg) return;
      var val = wSelect.value;
      if (val.length) {
        wImg.setAttribute('src', '{$ROOT_URL}' + val);
        wImg.style.display = '';
      } else {
        wImg.style.display = 'none';
      }
    }

    onWatermarkChange();

    var wSelect = document.getElementById("wSelect");
    if (wSelect) wSelect.addEventListener("change", onWatermarkChange);

    var positionChecked = document.querySelector("input[name='w[position]']:checked");
    var positionCustomDetails = document.getElementById("positionCustomDetails");
    if (positionChecked && positionCustomDetails) {
      positionCustomDetails.style.display = positionChecked.value === 'custom' ? '' : 'none';
    }

    document.querySelectorAll("input[name='w[position]']").forEach(function(el) {
      el.addEventListener('change', function() {
        var positionCustomDetails = document.getElementById("positionCustomDetails");
        if (positionCustomDetails) {
          positionCustomDetails.style.display = this.value === 'custom' ? '' : 'none';
        }
      });
    });

    document.querySelectorAll(".addWatermarkOpen").forEach(function(el) {
      el.addEventListener('click', function(event) {
        event.preventDefault();
        var addWatermark = document.getElementById("addWatermark");
        var selectWatermark = document.getElementById("selectWatermark");
        if (addWatermark) addWatermark.style.display = addWatermark.style.display === 'none' ? '' : 'none';
        if (selectWatermark) selectWatermark.style.display = selectWatermark.style.display === 'none' ? '' : 'none';
      });
    });
  }());
</script>{/footer_script}

<form method="post" action="{$F_ACTION}" class="properties" enctype="multipart/form-data">

  <div id="configContent">

    <fieldset id="watermarkConf" class="no-border">
      <legend></legend>
      <ul>
        <li>
          <span id="selectWatermark" {if isset($ferrors.watermarkImage)} style="display:none"
            {/if}><label>{'Select a file'|translate}</label>
            <select name="w[file]" id="wSelect">
              {html_options options=$watermark_files selected=$watermark.file}
            </select>

            {'... or '|translate}<a href="#" class="addWatermarkOpen">{'add a new watermark'|translate}</a>
            <br>
            <img id="wImg"></img>
          </span>

          <span id="addWatermark" {if isset($ferrors.watermarkImage)} style="display:inline" {/if}>
            {'add a new watermark'|translate} {'... or '|translate}<a href="#"
              class="addWatermarkOpen">{'Select a file'|translate}</a>

            <br>
            <input type="file" size="60" id="watermarkImage" name="watermarkImage" {if isset($ferrors.watermarkImage)}
              class="dError" {/if}> (png)
            {if isset($ferrors.watermarkImage)}<span class="dErrorDesc"
              title="{$ferrors.watermarkImage|htmlspecialchars}">!</span>{/if}
          </span>
        </li>

        <li>
          <label>
            {'Apply watermark if width is bigger than'|translate}
            <input size="4" maxlength="4" type="text" name="w[minw]" value="{$watermark.minw}"
              {if isset($ferrors.watermark.minw)} class="dError" {/if}>
          </label>
          {'pixels'|translate}
        </li>

        <li>
          <label>
            {'Apply watermark if height is bigger than'|translate}
            <input size="4" maxlength="4" type="text" name="w[minh]" value="{$watermark.minh}"
              {if isset($ferrors.watermark.minh)} class="dError" {/if}>
          </label>
          {'pixels'|translate}
        </li>

        <li>
          <label>{'Position'|translate}</label>
          <br>
          <div id="watermarkPositionBox">
            <label class="right font-checkbox">{'top right corner'|translate} <span
                class="icon-dot-circled"></span><input name="w[position]" type="radio" value="topright"
                {if $watermark.position eq 'topright'} checked="checked" {/if}></label>
            <label class="font-checkbox"><span class="icon-dot-circled"></span><input name="w[position]" type="radio"
                value="topleft" {if $watermark.position eq 'topleft'} checked="checked" {/if}>
              {'top left corner'|translate}</label>
            <label class="middle font-checkbox"><span class="icon-dot-circled"></span><input name="w[position]"
                type="radio" value="middle" {if $watermark.position eq 'middle'} checked="checked" {/if}>
              {'middle'|translate}</label>
            <label class="right font-checkbox">{'bottom right corner'|translate} <span
                class="icon-dot-circled"></span><input name="w[position]" type="radio" value="bottomright"
                {if $watermark.position eq 'bottomright'} checked="checked" {/if}></label>
            <label class="font-checkbox"><span class="icon-dot-circled"></span><input name="w[position]" type="radio"
                value="bottomleft" {if $watermark.position eq 'bottomleft'} checked="checked" {/if}>
              {'bottom left corner'|translate}</label>
          </div>

          <label class="font-checkbox" style="display:block;margin-top:10px;font-weight:normal;"><span
              class="icon-dot-circled"></span><input name="w[position]" type="radio" value="custom"
              {if $watermark.position eq 'custom'} checked="checked" {/if}> {'custom'|translate}</label>

          <div id="positionCustomDetails">
            <label>{'X Position'|translate}
              <input size="3" maxlength="3" type="text" name="w[xpos]" value="{$watermark.xpos}"
                {if isset($ferrors.watermark.xpos)} class="dError" {/if}>%
              {if isset($ferrors.watermark.xpos)}<span class="dErrorDesc"
                title="{$ferrors.watermark.xpos}">!</span>{/if}
            </label>

            <br>
            <label>{'Y Position'|translate}
              <input size="3" maxlength="3" type="text" name="w[ypos]" value="{$watermark.ypos}"
                {if isset($ferrors.watermark.ypos)} class="dError" {/if}>%
              {if isset($ferrors.watermark.ypos)}<span class="dErrorDesc"
                title="{$ferrors.watermark.ypos}">!</span>{/if}
            </label>

            <br>
            <label>{'X Repeat'|translate}
              <input size="3" maxlength="3" type="text" name="w[xrepeat]" value="{$watermark.xrepeat}"
                {if isset($ferrors.watermark.xrepeat)} class="dError" {/if}>
              {if isset($ferrors.watermark.xrepeat)}<span class="dErrorDesc"
                title="{$ferrors.watermark.xrepeat}">!</span>{/if}
            </label>

            <br>
            <label>{'Y Repeat'|translate}
              <input size="3" maxlength="3" type="text" name="w[yrepeat]" value="{$watermark.yrepeat}"
                {if isset($ferrors.watermark.yrepeat)} class="dError" {/if}>
              {if isset($ferrors.watermark.yrepeat)}<span class="dErrorDesc"
                title="{$ferrors.watermark.yrepeat}">!</span>{/if}
            </label>

          </div>
        </li>

        <li>
          <label>{'Opacity'|translate}</label>
          <input size="3" maxlength="3" type="text" name="w[opacity]" value="{$watermark.opacity}"
            {if isset($ferrors.watermark.opacity)} class="dError" {/if}> %
          {if isset($ferrors.watermark.opacity)}<span class="dErrorDesc"
            title="{$ferrors.watermark.opacity}">!</span>{/if}
        </li>
      </ul>
    </fieldset>

  </div> <!-- configContent -->

  <p class="formButtons">
    <button name="submit" type="submit" class="buttonLike" {if $isWebmaster != 1}disabled{/if}>
      <i class="icon-floppy"></i> {'Save Settings'|translate}
    </button>
  </p>

  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
</form>