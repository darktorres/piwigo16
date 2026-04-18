{include file='inc/autosize.inc.tpl'}
{include file='inc/datepicker.inc.tpl'}
{include file='inc/colorbox.inc.tpl'}

{combine_css path='node_modules/tom-select/dist/css/tom-select.default.css'}

{footer_script}<script>
  window.tagsServerKey = '{$CACHE_KEYS.tags|default:''}';
  window.tagsCacheServerId = '{$CACHE_KEYS._hash|default:''}';
  window.rootUrl = '{$ROOT_URL}';
  window.str_create = '{'Create'|translate}';
  window.str_cancel = '{'Cancel'|translate}';
</script>{/footer_script}

{if $vite_batch_manager_unit}
<script type="module" src="admin/themes/default/js/dist/{$vite_batch_manager_unit}"></script>
{/if}

<form action="{$F_ACTION}" method="POST">
  <div style="margin: 30px 0; display: flex; justify-content: space-between;">
    <div style="margin-left: 22px;">
      {if !empty($navbar) }{include file='navigation_bar.tpl'|get_extent:'navbar'}{/if}
    </div>
    <div style="margin-right: 21px;" class="pagination-per-page">
      <span style="font-weight: bold;color: unset;">{'photos per page'|translate} :</span>
      <a href="{$U_ELEMENTS_PAGE}&amp;display=5">5</a>
      <a href="{$U_ELEMENTS_PAGE}&amp;display=10">10</a>
      <a href="{$U_ELEMENTS_PAGE}&amp;display=50">50</a>
    </div>
  </div>
  <div style="clear:both"></div>

  {if !empty($elements) }
    <div><input type="hidden" name="element_ids" value="{$ELEMENT_IDS}"></div>
    {foreach $elements as $element}
      <fieldset class="elementEdit">
        <legend>{$element.LEGEND}</legend>

        <span class="thumb">
          <a href="{$element.FILE_SRC}" class="preview-box icon-zoom-in" title="{$element.LEGEND|htmlspecialchars}"><img
              src="{$element.TN_SRC}" alt=""
              {if $element.is_svg}style="{if $current.width < 100}min-width: 100px;{/if}{if $current.height < 100} min-height: 100px; {/if}"
            {/if}></a>
        <a href="{$element.U_EDIT}" class="icon-pencil">{'Edit'|translate}</a>
      </span>

      <table>

        <tr>
          <td><strong>{'Title'|translate}</strong></td>
          <td><input type="text" class="large" name="name-{$element.id}" value="{$element.NAME}"></td>
        </tr>

        <tr>
          <td><strong>{'Author'|translate}</strong></td>
          <td><input type="text" class="large" name="author-{$element.id}" value="{$element.AUTHOR}"></td>
        </tr>

        <tr>
          <td><strong>{'Creation date'|translate}</strong></td>
          <td>
            <input type="hidden" name="date_creation-{$element.id}" value="{$element.DATE_CREATION}">
            <label>
              <i class="icon-calendar"></i>
              <input type="text" data-datepicker="date_creation-{$element.id}"
                data-datepicker-unset="date_creation_unset-{$element.id}" readonly>
            </label>
            <a href="#" class="icon-cancel-circled" id="date_creation_unset-{$element.id}">{'unset'|translate}</a>
          </td>
        </tr>
        <tr>
          <td><strong>{'Who can see this photo?'|translate}</strong><br>({'Privacy level'|translate})</td>
          <td>
            <select name="level-{$element.id}">
              {html_options options=$level_options selected=$element.LEVEL}
            </select>
          </td>
        </tr>

        <tr>
          <td><strong>{'Tags'|translate}</strong></td>
          <td>
            <select data-selectize="tags" data-value="{$element.TAGS|json_encode|escape:html}"
              placeholder="{'Type in a search term'|translate}" data-create="true" name="tags-{$element.id}[]" multiple
              style="width:500px;"></select>
          </td>
        </tr>

        <tr>
          <td><strong>{'Description'|translate}</strong></td>
          <td><textarea cols="50" rows="5" name="description-{$element.id}" id="description-{$element.id}"
              class="description">{$element.DESCRIPTION}</textarea></td>
        </tr>

      </table>

    </fieldset>
  {/foreach}

  {if !empty($navbar)}{include file='navigation_bar.tpl'|get_extent:'navbar'}{/if}

  <p>
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
    <button class="buttonLike" type="submit" value="{'Submit'|translate}" name="submit"><i
        class="icon-floppy"></i>{'Submit'|translate}</button>
    <button class="resetButton" type="reset" value="{'Reset'|translate}" name="reset">{'Reset'|translate}</button>

    {* <span class="buttonLike" type="submit" name="submit"> {'Submit'|translate}</span> *}
    {* <input type="reset" value="{'Reset'|translate}"> *}
  </p>
  {/if}

</form>

<style>
  .ts-control .item,
  .ts-control .item.active {
    background-image: none !important;
    background-color: #ffa646 !important;
    border-color: transparent !important;
    color: black !important;

    border-radius: 20px !important;
  }

  .ts-control .item .remove,
  .ts-control .item .remove {
    background-color: transparent !important;
    border-top-right-radius: 20px !important;
    border-bottom-right-radius: 20px !important;
    color: black !important;

    border-left: 1px solid transparent !important;

  }

  .ts-control .item .remove:hover,
  .ts-control .item .remove:hover {
    background-color: #ff7700 !important;
  }

  .thumb {
    float: right;
  }
</style>