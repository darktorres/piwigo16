<script id="pwg-page-data" type="application/json">{$page_data_json}</script>
{combine_script id='common' load='footer' path='themes/admin/_base/js/common.js'}
{combine_script id='configuration_sizes' load='footer' require='common' path='themes/admin/_base/js/configuration_sizes.js'}

{combine_css path="themes/admin/_base/css/pages/configuration_sizes.css"}

<form method="post" action="{$F_ACTION}" class="properties">

<div id="configContent">

  <fieldset id="sizesConf">
    <legend><span class="icon-picture icon-red"></span>{'Original Size'|translate}</legend>
  {if (isset($is_gd) and $is_gd)}
    <div>
      {'Resize after upload disabled due to the use of GD as graphic library'|translate}
      <input type="checkbox" name="original_resize" disabled="disabled" class="u-invisible">
      <input type="hidden" name="original_resize_maxwidth" value="{$sizes.original_resize_maxwidth}">
      <input type="hidden" name="original_resize_maxheight" value="{$sizes.original_resize_maxheight}">
      <input type="hidden" name="original_resize_quality" value="{$sizes.original_resize_quality}">
    </div>
  {else}
    <div>
      <label class="font-checkbox">
        <span class="icon-check"></span>
        <input type="checkbox" name="original_resize" {if (isset($sizes.original_resize) and $sizes.original_resize)}checked="checked"{/if}>
        {'Resize after upload'|translate}
      </label>
    </div>

    <table id="sizeEdit-original">
      <tr>
        <th>{'Maximum width'|translate}</th>
        <td>
          <input type="text" name="original_resize_maxwidth" value="{$sizes.original_resize_maxwidth}" size="4" maxlength="4"{if isset($ferrors.original_resize_maxwidth)} class="dError"{/if}> {'pixels'|translate}
          {if isset($ferrors.original_resize_maxwidth)}<span class="dErrorDesc" title="{$ferrors.original_resize_maxwidth}">!</span>{/if}
        </td>
      </tr>
      <tr>
        <th>{'Maximum height'|translate}</th>
        <td>
          <input type="text" name="original_resize_maxheight" value="{$sizes.original_resize_maxheight}" size="4" maxlength="4"{if isset($ferrors.original_resize_maxheight)} class="dError"{/if}> {'pixels'|translate}
          {if isset($ferrors.original_resize_maxheight)}<span class="dErrorDesc" title="{$ferrors.original_resize_maxheight}">!</span>{/if}
        </td>
      </tr>
      <tr>
        <th>{'Image Quality'|translate}</th>
        <td>
          <input type="text" name="original_resize_quality" value="{$sizes.original_resize_quality}" size="3" maxlength="3"{if isset($ferrors.original_resize_quality)} class="dError"{/if}> %
          {if isset($ferrors.original_resize_quality)}<span class="dErrorDesc" title="{$ferrors.original_resize_quality}">!</span>{/if}
        </td>
      </tr>
    </table>
  {/if}
  </fieldset>

  <fieldset id="multiSizesConf">
    <legend><span class="icon-th icon-purple"></span>{'Multiple Size'|translate}</legend>

    <div class="showDetails">
      <a href="#" id="showDetails"{if isset($ferrors)} hidden{/if}>{'show details'|translate}</a>
    </div>

    <table class="u-m-0">
    {foreach from=$derivatives item=d key=type}
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
              <input type="checkbox" name="d[{$type}][enabled]" {if $d.enabled}checked="checked"{/if}>
            </span>
            {/if}
            {$type|translate}
          </label>
        </td>

        <td>
          <span class="sizeDetails{if isset($ferrors)} u-d-inline{/if}">{$d.w} x {$d.h} {'pixels'|translate}{if $d.crop}, {'Crop'|translate|lower}{/if}</span>
        </td>

        <td>
          <span class="sizeDetails{if isset($ferrors) and !isset($ferrors.$type)} u-d-inline{/if}">
            <a href="#" id="sizeEditOpen-{$type}" class="sizeEditOpen">{'edit'|translate}</a>
          </span>
        </td>
      </tr>

      <tr id="sizeEdit-{$type}" class="sizeEdit{if isset($ferrors.$type)} u-d-block{/if}">
        <td colspan="3">
          <table class="sizeEditForm">
          {if !$d.must_square}
            <tr>
              <td colspan="2">
                <label class="font-checkbox">
                <span class="icon-check"></span>
                <input type="checkbox" class="cropToggle" name="d[{$type}][crop]" {if $d.crop}checked="checked"{/if}>
                  {'Crop'|translate}
                </label>
              </td>
            </tr>
          {/if}
            <tr>
              <td class="sizeEditWidth">{if $d.must_square or $d.crop}{'Width'|translate}{else}{'Maximum width'|translate}{/if}</td>
              <td>
                <input type="text" name="d[{$type}][w]" maxlength="4" size="4" value="{$d.w}"{if isset($ferrors.$type.w)} class="dError"{/if}> {'pixels'|translate}
                {if isset($ferrors.$type.w)}<span class="dErrorDesc" title="{$ferrors.$type.w}">!</span>{/if}
              </td>
            </tr>
          {if !$d.must_square}
            <tr>
              <td class="sizeEditHeight">{if $d.crop}{'Height'|translate}{else}{'Maximum height'|translate}{/if}</td>
              <td>
                <input type="text" name="d[{$type}][h]" maxlength="4" size="4"  value="{$d.h}"{if isset($ferrors.$type.h)} class="dError"{/if}> {'pixels'|translate}
                {if isset($ferrors.$type.h)}<span class="dErrorDesc" title="{$ferrors.$type.h}">!</span>{/if}
              </td>
            </tr>
          {/if}
            <tr>
              <td>{'Sharpen'|translate}</td>
              <td>
                <input type="text" name="d[{$type}][sharpen]" maxlength="4" size="4"  value="{$d.sharpen}"{if isset($ferrors.$type.sharpen)} class="dError"{/if}> %
                {if isset($ferrors.$type.sharpen)}<span class="dErrorDesc" title="{$ferrors.$type.sharpen}">!</span>{/if}
              </td>
            </tr>
          </table> {* #sizeEdit *}
        </td>
      </tr>
    {/foreach}
    </table>

  <p class="sizeDetails sizeDetails-row{if isset($ferrors)} u-d-block{/if}">
    {'Image Quality'|translate}
    <input type="text" name="resize_quality" value="{$resize_quality}" size="3" maxlength="3"{if isset($ferrors.resize_quality)} class="dError"{/if}> %
    {if isset($ferrors.resize_quality)}<span class="dErrorDesc" title="{$ferrors.resize_quality}">!</span>{/if}
  </p>
  <p class="sizeDetails sizeDetails-row{if isset($ferrors)} u-d-block{/if}">
    <a href="{$F_ACTION}&action=restore_settings" class="restore-settings-button">{'Reset to default values'|translate}</a>
  </p>

  </fieldset>

</div> <!-- configContent -->

  <div class="savebar-footer">
    <div class="savebar-footer-start">
    </div>
    <div class="savebar-footer-end">
{if isset($save_success)}
      <div class="savebar-footer-block">
        <div class="badge info-message">
          <i class="icon-ok"></i>{$save_success}
        </div>
      </div>
{/if}
      <div class="savebar-footer-block">
        <button class="buttonLike"  type="submit" name="submit" {if $isWebmaster != 1}disabled{/if}><i class="icon-floppy"></i> {'Save Settings'|translate}</button>
      </div>    
    </div>
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
  </div>

</form>