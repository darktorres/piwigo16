{include file='include/autosize.inc.tpl'}
{include file='include/datepicker.inc.tpl'}

<script id="pwg-picture-modify-data" type="application/json">{$picture_modify_page_data_json}</script>

{footer_script}
{* <!-- DATEPICKER --> *}
document.querySelectorAll('[data-datepicker]').forEach(function(el) {
  window.pwgDatepicker(el, {
    showTimepicker: true,
    cancelButton: '{'Cancel'|translate}'
  });
});

{* <!-- THUMBNAILS --> *}
{literal}
GLightbox({selector: 'a.preview-box'});
{/literal}

window.str_are_you_sure = '{'Are you sure?'|translate|escape:javascript}';
window.str_yes = '{'Yes, delete'|translate|escape:javascript}';
window.str_no = '{'No, I have changed my mind'|translate|@escape:'javascript'}';
window.url_delete = '{$U_DELETE}';
window.str_orphan = '{'This photo is an orphan'|@translate|escape:javascript}';


window.related_categories_ids = {$related_categories_ids|@json_encode};

document.getElementById('action-delete-picture').addEventListener('click', function() {
  if (window.confirm(str_are_you_sure)) {
    window.location.href = url_delete.replaceAll('amp;', '');
  }
});
{/footer_script}

{combine_script id='picture_modify' load='footer' path='admin/themes/default/js/picture_modify.js'}
{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}

<form action="{$F_ACTION}" method="post" id="pictureModify">
{if $INTRO.is_svg}
  <div id='picture-preview' class="svg-container">
{else}
  <div id='picture-preview'>
{/if}
    <div class='picture-preview-actions'>
      <a class="preview-box icon-zoom-square" href="{$FILE_SRC}" title="{'Zoom'|translate}"></a>
      <a class="icon-download" href="{$U_DOWNLOAD}" title="{'Download'|translate}"></a>
      <a class="icon-signal" href="{$U_HISTORY}" title="{'Visit history'|translate}"></a>
      <a class="icon-pulse" href="{$U_ACTIVITY}" title="{'Activity'|translate}"></a>
      {if !url_is_remote($PATH)}
      <a class="icon-arrows-cw" href="{$U_SYNC}" title="{'Synchronize metadata'|@translate}"></a>
      <a class="icon-trash" title="{'delete photo'|@translate}" id='action-delete-picture'></a>
      {/if}
    </div>
      {if $INTRO.is_svg}
      <img src="{$PATH}" alt="{'Thumbnail'|translate}" class="svg-image other-image-format" style="{if $FORMAT}width:100%; max-height:100%; {else}max-width:100%; height:100%;{/if} object-fit:contain;">
      {else}
      <img src="{$TN_SRC}" alt="{'Thumbnail'|translate}" class="other-image-format" style="{if $FORMAT}width:100%; max-height:100%;{else}max-width:100%; height:100%;{/if} object-fit:contain;">
      {/if}
  </div>
  <div id='picture-content'>
    <div id='picture-infos'>
      <div class='info-framed'>
        <div class='info-framed-icon'>
          <i class='icon-picture'></i>
        </div>
        <div class='info-framed-container'>
          <div class='info-framed-title'>{$INTRO.file}</div>
          <div>{$INTRO.size}</div>
          <div>{if isset($INTRO.formats)}{$INTRO.formats} {/if}</div>
          <div>{$INTRO.ext}</div>
        </div>
      </div>

      <div class='info-framed'>
        <div class='info-framed-icon'>
          <span class='icon-calendar'></span>
        </div>
        <div class='info-framed-container'>
          <div class='info-framed-title'>{$INTRO.date}</div>
          <div>{$INTRO.age}</div>
          <div>{$INTRO.added_by}</div>
          <div>{$INTRO.stats}</div>
        </div>
      </div>
    </div>


    <p>
      <strong>{'Title'|@translate}</strong>
      <br>
      <input type="text" class="large" name="name" value="{$NAME|@escape}">
    </p>

    <p>
      <strong>{'Author'|@translate}</strong>
      <br>
      <input type="text" class="large" name="author" value="{$AUTHOR}">
    </p>

    <p>
      <strong>{'Creation date'|@translate}</strong>
      <br>
      <input type="hidden" name="date_creation" value="{$DATE_CREATION}">
      <label class="date-input">
        <i class="icon-calendar"></i>
        <input type="text" data-datepicker="date_creation" data-datepicker-unset="date_creation_unset" readonly>
      </label>
      <a href="#" class="icon-cancel-circled" id="date_creation_unset">{'unset'|translate}</a>
    </p>

    <p>
      <strong>{'Linked albums'|@translate} <span class="linked-albums-badge {if $related_categories|@count < 1 } badge-red {/if}"> {$related_categories|@count} </span></strong>
      {if $related_categories|@count < 1}
        <span class="orphan-photo">{'This photo is an orphan'|@translate}</span>
      {else}
        <span class="orphan-photo"></span>
      {/if}
      <br>
      <select class="invisible-related-categories-select" name="associate[]" multiple>
      {foreach from=$related_categories item=cat_path key=key}
        <option selected value="{$key}"></option>
      {/foreach}
      </select>
      <div class="related-categories-container">
      {foreach from=$related_categories item=cat_path key=key}
      <div class="breadcrumb-item"><span class="link-path">{$cat_path['name']}</span>{if $cat_path['unlinkable']}<span id={$key} class="icon-cancel-circled remove-item"></span>{else}<span id={$key} class="icon-help-circled help-item tiptip" title="{'This picture is physically linked to this album, you can\'t dissociate them'|translate}"></span>{/if}</div>
      {/foreach}
      </div>
      <div class="breadcrumb-item linked-albums add-item {if $related_categories|@count < 1 } highlight {/if}"><span class="icon-plus-circled"></span>{'Add'|translate}</div>
    </p>

    <p>
      <strong>{'Representation of albums'|@translate}</strong>
      <br>
      <select data-selectize="categories" data-value="{$represented_albums|@json_encode|escape:html}"
        placeholder="{'Type in a search term'|translate}"
        name="represent[]" multiple style="width:calc(100% + 2px);"></select>
    </p>

    <p>
      <strong>{'Tags'|@translate}</strong>
      <br>
      <select data-selectize="tags" data-value="{$tag_selection|@json_encode|escape:html}"
        placeholder="{'Type in a search term'|translate}"
        data-create="true" name="tags[]" multiple style="width:calc(100% + 2px);"></select>
    </p>

    <p>
      <strong>{'Description'|@translate}</strong>
      <br>
      <textarea name="comment" id="description" class="description">{$DESCRIPTION}</textarea>
    </p>

    <p>
      <strong>{'Who can see this photo?'|@translate}</strong> ({'Privacy level'|translate})
      <br>
      <div class='select-icon icon-down-open'> </div>
      <select name="level" size="1">
        {html_options options=$level_options selected=$level_options_selected}
      </select>
   </p>

   <div class="savebar-footer">
      <div class="savebar-footer-start">
        <div class="savebar-footer-block">
{if isset($U_JUMPTO)}
          <a class="savebar-see-out" href="{$U_JUMPTO}" ><i class="icon-left-open"></i>{'Open in gallery'|@translate}</a>
{else}
          <a class="savebar-see-out tiptip disabled" href="#" title="{'You don\'t have access to this photo'|translate}"><i class="icon-left-open"></i>{'Open in gallery'|translate}</a>
{/if}
        </div>
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
          <button class="buttonLike"  type="submit" name="submit"><i class="icon-floppy"></i> {'Save Settings'|@translate}</button>
        </div>
      </div>
      <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
    </div>
    
  </div>

</form>

{include file='include/album_selector.inc.tpl'}

{combine_css path="admin/themes/default/css/pages/picture_modify.css"}
