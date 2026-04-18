{if $vite_configuration}
<script type="module" src="admin/themes/default/js/dist/{$vite_configuration}"></script>
{/if}

{footer_script}<script>
  const title_msg = '{'Are you sure you want to restore to default settings?'|translate|escape:javascript}';
  const confirm_msg = '{'Yes, I am sure'|translate|escape}';
  const cancel_msg = '{'No, I have changed my mind'|translate|escape}';

  document.querySelectorAll(".restore-settings-button").forEach(function(el) {
    pwgConfirmFollowHref(el, {
      alert_title: title_msg,
      alert_confirm: confirm_msg,
      alert_cancel: cancel_msg
    });
  });

  (function() {
    var labelMaxWidth = "{'Maximum width'|translate}",
    labelWidth = "{'Width'|translate}",
    labelMaxHeight = "{'Maximum height'|translate}",
    labelHeight = "{'Height'|translate}";

    function toggleResizeFields(size) {
      var checkbox = document.querySelector("[name=original_resize]");
      var needToggle = document.getElementById("sizeEdit-original");
      if (!checkbox || !needToggle) return;
      needToggle.style.display = checkbox.checked ? '' : 'none';
    }

    toggleResizeFields("original");
    var originalResizeCheckbox = document.querySelector("[name=original_resize]");
    if (originalResizeCheckbox) {
      originalResizeCheckbox.addEventListener('click', function() {
        toggleResizeFields("original");
      });
    }

    document.querySelectorAll("a[id^='sizeEditOpen-']").forEach(function(el) {
      el.addEventListener('click', function(event) {
        event.preventDefault();
        var sizeName = this.id.split("-")[1];
        var sizeEdit = document.getElementById("sizeEdit-" + sizeName);
        if (sizeEdit) sizeEdit.style.display = sizeEdit.style.display === 'none' || sizeEdit.style.display === '' ? 'block' : 'none';
        this.style.display = 'none';
      });
    });

    document.querySelectorAll(".cropToggle").forEach(function(el) {
      el.addEventListener('click', function() {
        var form = this.closest('table.sizeEditForm');
        if (!form) return;
        var labelBoxWidth = form.querySelector('td.sizeEditWidth');
        var labelBoxHeight = form.querySelector('td.sizeEditHeight');
        if (this.checked) {
          if (labelBoxWidth) labelBoxWidth.innerHTML = labelWidth;
          if (labelBoxHeight) labelBoxHeight.innerHTML = labelHeight;
        } else {
          if (labelBoxWidth) labelBoxWidth.innerHTML = labelMaxWidth;
          if (labelBoxHeight) labelBoxHeight.innerHTML = labelMaxHeight;
        }
      });
    });

    var showDetailsBtn = document.getElementById("showDetails");
    if (showDetailsBtn) {
      showDetailsBtn.addEventListener('click', function(event) {
        event.preventDefault();
        document.querySelectorAll(".sizeDetails").forEach(function(el) { el.style.display = ''; });
        this.style.visibility = 'hidden';
      });
    }
  })();
</script>{/footer_script}

{html_style}<style>
  .sizeEnable {
    width: 50px;
  }

  .sizeEnable .icon-ok {
    position: relative;
    left: 2px;
  }

  .sizeEditForm {
    margin: 0 0 10px 20px;
  }

  .sizeEdit {
    display: none;
  }

  #sizesConf table {
    margin: 0;
  }

  .showDetails {
    padding: 0;
  }

  .sizeDetails {
    display: none;
    margin-left: 10px;
  }

  .sizeEditOpen {
    margin-left: 10px;
  }
</style>{/html_style}

<form method="post" action="{$F_ACTION}" class="properties">

  <div id="configContent">

    <fieldset id="sizesConf">
      <legend><span class="icon-picture icon-red"></span>{'Original Size'|translate}</legend>
      {if (isset($is_gd) and $is_gd)}
        <div>
          {'Resize after upload disabled due to the use of GD as graphic library'|translate}
          <input type="checkbox" name="original_resize" disabled="disabled" style="visibility: hidden">
          <input type="hidden" name="original_resize_maxwidth" value="{$sizes.original_resize_maxwidth}">
          <input type="hidden" name="original_resize_maxheight" value="{$sizes.original_resize_maxheight}">
          <input type="hidden" name="original_resize_quality" value="{$sizes.original_resize_quality}">
        </div>
      {else}
        <div>
          <label class="font-checkbox">
            <span class="icon-check"></span>
            <input type="checkbox" name="original_resize"
              {if (isset($sizes.original_resize) and $sizes.original_resize)}checked="checked" {/if}>
            {'Resize after upload'|translate}
          </label>
        </div>

        <table id="sizeEdit-original">
          <tr>
            <th>{'Maximum width'|translate}</th>
            <td>
              <input type="text" name="original_resize_maxwidth" value="{$sizes.original_resize_maxwidth}" size="4"
                maxlength="4" {if isset($ferrors.original_resize_maxwidth)} class="dError" {/if}> {'pixels'|translate}
              {if isset($ferrors.original_resize_maxwidth)}<span class="dErrorDesc"
                title="{$ferrors.original_resize_maxwidth}">!</span>{/if}
            </td>
          </tr>
          <tr>
            <th>{'Maximum height'|translate}</th>
            <td>
              <input type="text" name="original_resize_maxheight" value="{$sizes.original_resize_maxheight}" size="4"
                maxlength="4" {if isset($ferrors.original_resize_maxheight)} class="dError" {/if}> {'pixels'|translate}
              {if isset($ferrors.original_resize_maxheight)}<span class="dErrorDesc"
                title="{$ferrors.original_resize_maxheight}">!</span>{/if}
            </td>
          </tr>
          <tr>
            <th>{'Image Quality'|translate}</th>
            <td>
              <input type="text" name="original_resize_quality" value="{$sizes.original_resize_quality}" size="3"
                maxlength="3" {if isset($ferrors.original_resize_quality)} class="dError" {/if}> %
              {if isset($ferrors.original_resize_quality)}<span class="dErrorDesc"
                title="{$ferrors.original_resize_quality}">!</span>{/if}
            </td>
          </tr>
        </table>
      {/if}
    </fieldset>

    <fieldset id="multiSizesConf">
      <legend><span class="icon-th icon-purple"></span>{'Multiple Size'|translate}</legend>

      <div class="showDetails">
        <a href="#" id="showDetails" {if isset($ferrors)} style="display:none" {/if}>{'show details'|translate}</a>
      </div>

      <table style="margin:0">
        {foreach $derivatives as $type => $d}
          <tr>
            <td>
              <label>
                {if $d.must_enable}
                  <span class="sizeEnable">
                    <span class="icon-ok"></span>
                  </span>
                {else}
                  <span class="sizeEnable font-checkbox">
                    <span class="icon-check"></span>
                    <input type="checkbox" name="d[{$type}][enabled]" {if $d.enabled}checked="checked" {/if}>
                  </span>
                {/if}
                {$type|translate}
              </label>
            </td>

            <td>
              <span class="sizeDetails" {if isset($ferrors)} style="display:inline" {/if}>{$d.w} x {$d.h}
                {'pixels'|translate}{if $d.crop}, {'Crop'|translate|lower}{/if}</span>
            </td>

            <td>
              <span class="sizeDetails" {if isset($ferrors) and !isset($ferrors.$type)} style="display:inline" {/if}>
                <a href="#" id="sizeEditOpen-{$type}" class="sizeEditOpen">{'edit'|translate}</a>
              </span>
            </td>
          </tr>

          <tr id="sizeEdit-{$type}" class="sizeEdit" {if isset($ferrors.$type)} style="display:block" {/if}>
            <td colspan="3">
              <table class="sizeEditForm">
                {if !$d.must_square}
                  <tr>
                    <td colspan="2">
                      <label class="font-checkbox">
                        <span class="icon-check"></span>
                        <input type="checkbox" class="cropToggle" name="d[{$type}][crop]" {if $d.crop}checked="checked"
                          {/if}>
                        {'Crop'|translate}
                      </label>
                    </td>
                  </tr>
                {/if}
                <tr>
                  <td class="sizeEditWidth">
                    {if $d.must_square or $d.crop}{'Width'|translate}{else}{'Maximum width'|translate}{/if}</td>
                  <td>
                    <input type="text" name="d[{$type}][w]" maxlength="4" size="4" value="{$d.w}"
                      {if isset($ferrors.$type.w)} class="dError" {/if}> {'pixels'|translate}
                    {if isset($ferrors.$type.w)}<span class="dErrorDesc" title="{$ferrors.$type.w}">!</span>{/if}
                  </td>
                </tr>
                {if !$d.must_square}
                  <tr>
                    <td class="sizeEditHeight">{if $d.crop}{'Height'|translate}{else}{'Maximum height'|translate}{/if}</td>
                    <td>
                      <input type="text" name="d[{$type}][h]" maxlength="4" size="4" value="{$d.h}"
                        {if isset($ferrors.$type.h)} class="dError" {/if}> {'pixels'|translate}
                      {if isset($ferrors.$type.h)}<span class="dErrorDesc" title="{$ferrors.$type.h}">!</span>{/if}
                    </td>
                  </tr>
                {/if}
                <tr>
                  <td>{'Sharpen'|translate}</td>
                  <td>
                    <input type="text" name="d[{$type}][sharpen]" maxlength="4" size="4" value="{$d.sharpen}"
                      {if isset($ferrors.$type.sharpen)} class="dError" {/if}> %
                    {if isset($ferrors.$type.sharpen)}<span class="dErrorDesc"
                      title="{$ferrors.$type.sharpen}">!</span>{/if}
                  </td>
                </tr>
              </table> {* #sizeEdit *}
            </td>
          </tr>
        {/foreach}
      </table>

      <p style="margin:10px 0 0 0;{if isset($ferrors)} display:block;{/if}" class="sizeDetails">
        {'Image Quality'|translate}
        <input type="text" name="resize_quality" value="{$resize_quality}" size="3" maxlength="3"
          {if isset($ferrors.resize_quality)} class="dError" {/if}> %
        {if isset($ferrors.resize_quality)}<span class="dErrorDesc" title="{$ferrors.resize_quality}">!</span>{/if}
      </p>
      <p style="margin:10px 0 0 0;{if isset($ferrors)} display:block;{/if}" class="sizeDetails">
        <a href="{$F_ACTION}&action=restore_settings"
          class="restore-settings-button">{'Reset to default values'|translate}</a>
      </p>

    </fieldset>

  </div> <!-- configContent -->

  <p class="formButtons">
    <button name="submit" type="submit" class="buttonLike" {if $isWebmaster != 1}disabled{/if}>
      <i class="icon-floppy"></i> {'Save Settings'|translate}
    </button>
  </p>

  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
</form>