{include file='include/datepicker.inc.tpl' load_mode='async'}
{include file='include/add_album.inc.tpl' load_mode='async'}

{combine_script id='common' load='footer' path='themes/admin/_base/js/common.js'}

{combine_script id='batchManagerGlobal' load='async' require='datepicker,addAlbum' path='themes/admin/_base/js/batchManagerGlobal.js'}

<script id="pwg-batch-manager-global-data" type="application/json">{$batch_manager_global_page_data_json}</script>


{combine_css path="themes/admin/_base/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}

<div id="batchManagerGlobal">
  <form action="{$F_ACTION}" method="post">
  <input type="hidden" name="start" value="{$START}">
  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
  {include file='include/batch_manager_filter.inc.tpl' 
  title={'Batch Manager Filter'|translate}
  searchPlaceholder={'Filters'|translate}
  }
  <fieldset>

    <legend><span class='icon-check icon-blue '></span>{'Selection'|translate}</legend>

  {if !empty($thumbnails)}
  <p id="checkActions">
    <a href="#" id="selectAll">{if $nb_thumbs_set > $nb_thumbs_page}{'The whole page'|translate}{else}{'All'|translate}{/if}</a>
{if $nb_thumbs_set > $nb_thumbs_page}
    <a href="#" id="selectSet">{'The whole set'|translate}</a>
{/if}
    <a href="#" id="selectNone">{'None'|translate}</a>
    <a href="#" id="selectInvert">{'Invert'|translate}</a>

    <span id="selectedMessage"></span>

    <input type="checkbox" name="setSelected" hidden {if count($selection) == $nb_thumbs_set}checked="checked"{/if}>
    <input type="hidden" name="whole_set" value="">
  </p>

	<ul class="thumbnails" style="--bm-thumb-w:{$thumb_params->maxWidth()+2}px;--bm-thumb-h:{$thumb_params->maxHeight()+25}px">
		{foreach from=$thumbnails item=thumbnail}
		{assign var='isSelected' value=$thumbnail.id|@in_array:$selection}
		<li{if $isSelected} class="thumbSelected"{/if}>
			<span class="wrap1">
				<label class="font-checkbox">
					<span class="icon-check"></span><input type="checkbox" name="selection[]" value="{$thumbnail.id}" {if $isSelected}checked="checked"{/if}>
					<span class="wrap2">
					<div class="actions">
            <a href="{$thumbnail.U_EDIT}" target="_blank" class="icon-pencil" title="{'Edit photo'|translate}"></a>
            <a href="{$thumbnail.FILE_SRC}" class="preview-box icon-zoom-square" title="{'Zoom'|translate}"></a>
          </div>
						{if $thumbnail.level > 0}
						<em class="levelIndicatorF" title="{'Who can see these photos?'|translate} : ">{'Level %d'|translate|sprintf:$thumbnail.level}</em>
						{/if}
						<img src="{$thumbnail.thumb->getUrl()}" alt="{$thumbnail.file}" title="{$thumbnail.TITLE|@escape:'html'}" {$thumbnail.thumb->getSizeHtm()}>
					</span>
				</label>
			</span>
		</li>
		{/foreach}
	</ul>

  {if !empty($navbar) }
  <div class="batchManager-pagination">
    <div class="pagination-per-page">
      <span>{'display'|translate}</span>
      <a href="{$U_DISPLAY}&amp;display=20">20</a>
      <a href="{$U_DISPLAY}&amp;display=50">50</a>
      <a href="{$U_DISPLAY}&amp;display=100">100</a>
      <a href="{$U_DISPLAY}&amp;display=all">{'all'|translate}</a>
    </div>

    {include file='navigation_bar.tpl'|@get_extent:'navbar'}
  </div>
  {/if}

  {else}
  <div class="selectionEmptyBlock">{'No photo in the current set.'|translate}</div>
  {/if}
  </fieldset>

  <fieldset id="action">

    <legend><span class='icon-cog icon-red'></span>{'Action'|translate}</legend>
      <div id="forbidAction"{if count($selection) != 0} hidden{/if}>{'No photos selected, no actions possible.'|translate}</div>
      <div id="permitAction"{if count($selection) == 0} hidden{/if}>
    
    <div class="permitActionListButton">
      <div>
        <select name="selectAction">
          <option value="-1">{'Choose an action'|translate}</option>
          <option disabled="disabled">------------------</option>
          <option value="delete" class="icon-trash">{'Delete selected photos'|translate}</option>
          <option value="associate">{'Associate to album'|translate}</option>
          <option value="move">{'Move to album'|translate}</option>
      {if !empty($associated_categories)}
          <option value="dissociate">{'Dissociate from album'|translate}</option>
      {/if}
          <option value="add_tags">{'Add tags'|translate}</option>
      {if !empty($associated_tags)}
          <option value="del_tags">{'remove tags'|translate}</option>
      {/if}
          <option value="author">{'Set author'|translate}</option>
          <option value="title">{'Set title'|translate}</option>
          <option value="date_creation">{'Set creation date'|translate}</option>
          <option value="level" class="icon-lock">{'Who can see these photos?'|translate} ({'Privacy level'|translate})</option>
          <option value="metadata">{'Synchronize metadata'|translate}</option>
      {if ($IN_CADDIE)}
          <option value="remove_from_caddie">{'Remove from caddie'|translate}</option>
      {else}
          <option value="add_to_caddie">{'Add to caddie'|translate}</option>
      {/if}
    		<option value="delete_derivatives">{'Delete multiple size images'|translate}</option>
    		<option value="generate_derivatives">{'Generate multiple size images'|translate}</option>
      {if !empty($element_set_global_plugins_actions)}
        {foreach from=$element_set_global_plugins_actions item=action}
          <option value="{$action.ID}">{$action.NAME}</option>
        {/foreach}
      {/if}
        </select>
      </div>
      <p id="confirmDel" class="u-invisible">
        <label class="font-checkbox">
          <span class="icon-check"></span>
          <input type="checkbox" name="confirm_deletion" value="1"> {'Are you sure?'|translate}</input>
        </label><br/><br/>
        <span class="errors u-invisible u-m-0">{"You need to confirm deletion"|translate}</span>
      </p>
      <p id="applyActionBlock" class="actionButtons" hidden>
        <button id="applyAction" name="submit" type="submit" class="buttonLike">
          <i class="icon-cog-alt"></i> {'Apply action'|translate}
        </button>

        <span id="applyOnDetails"></span>
      </p>
    </div>
    <div class="permitActionItem">
      <!-- delete -->
      <div id="action_delete" class="bulkAction">
      </div>

      <!-- associate -->
      <div id="action_associate" class="bulkAction">
        <div class="head-button-2 icon-plus-circled" id="associate_as">
          <p>{"Select an album"|translate}</p>
        </div>
        <div class="selected-associate-action">
        </div>
      </div>

      <!-- move -->
      <div id="action_move" class="bulkAction">
        <select data-selectize="categories" data-default="" name="move" class="u-w-600" placeholder="{'Select an album... or type it!'|translate}"></select>
        <a href="#" data-add-album="move" title="{'create a new album'|translate}" class="icon-plus"></a>
      </div>

      <!-- dissociate -->
      <div id="action_dissociate" class="bulkAction">
        <select data-selectize="categories" placeholder="{'Type in a search term'|translate}"
          name="dissociate" class="u-w-600"></select>
      </div>


      <!-- add_tags -->
      <div id="action_add_tags" class="bulkAction">
        <select data-selectize="tags" data-create="true" placeholder="{'Type in a search term'|translate}"
          name="add_tags[]" multiple class="u-w-400"></select>
      </div>

      <!-- del_tags -->
      <div id="action_del_tags" class="bulkAction">
  {if !empty($associated_tags)}
        <select data-selectize="tags" name="del_tags[]" multiple class="u-w-400"
          placeholder="{'Type in a search term'|translate}">
        {foreach from=$associated_tags item=tag}
          <option value="{$tag.id}">{$tag.name}</option>
        {/foreach}
        </select>
  {/if}
      </div>

      <!-- author -->
      <div id="action_author" class="bulkAction">
      <label class="font-checkbox"><span class="icon-check"></span><input type="checkbox" name="remove_author"> {'remove author'|translate}</label>
      <input type="text" class="large" name="author" placeholder="{'Type here the author name'|translate}">
      </div>

      <!-- title -->
      <div id="action_title" class="bulkAction">
      <label class="font-checkbox"><span class="icon-check"></span><input type="checkbox" name="remove_title"> {'remove title'|translate}</label>
      <input type="text" class="large" name="title" placeholder="{'Type here the title'|translate}">
      </div>

      <!-- date_creation -->
      <div id="action_date_creation" class="bulkAction">
        <label class="font-checkbox"><span class="icon-check"></span><input type="checkbox" name="remove_date_creation"> {'remove creation date'|translate}</label><br>
        <div id="set_date_creation">
          <input type="hidden" name="date_creation" value="{$DATE_CREATION}">
          <label>
            <i class="icon-calendar"></i>
            <input type="text" data-datepicker="date_creation" readonly>
          </label>
        </div>
      </div>

      <!-- level -->
      <div id="action_level" class="bulkAction">
          <select name="level" size="1">
            {html_options options=$level_options selected=$level_options_selected}
          </select>
      </div>

      <!-- metadata -->
      <div id="action_metadata" class="bulkAction">
      </div>

      <!-- generate derivatives -->
      <div id="action_generate_derivatives" class="bulkAction">
        <div class="deleteDerivButtons">
          <a href="#" data-deriv-select="generate-all">{'All'|translate}</a>
          <a href="#" data-deriv-select="generate-none">{'None'|translate}</a>
        </div>
        <br>
        {foreach from=$generate_derivatives_types key=type item=disp}
          <label class="font-checkbox"><span class="icon-check"></span><input type="checkbox" name="generate_derivatives_type[]" value="{$type}"> {$disp}</label>
        {/foreach}
      </div>

      <!-- delete derivatives -->
      <div id="action_delete_derivatives" class="bulkAction">
        <div class="deleteDerivButtons">
          <a href="#" data-deriv-select="delete-all">{'All'|translate}</a>
          <a href="#" data-deriv-select="delete-none">{'None'|translate}</a>
        </div>
        <br>
        {foreach from=$del_derivatives_types key=type item=disp}
          <label class="font-checkbox"><span class="icon-check"></span><input type="checkbox" name="del_derivatives_type[]" value="{$type}"> {$disp}</label>
        {/foreach}
      </div>

      <!-- plugins -->
  {if !empty($element_set_global_plugins_actions)}
    {foreach from=$element_set_global_plugins_actions item=action}
      <div id="action_{$action.ID}" class="bulkAction">
      {if !empty($action.CONTENT)}{$action.CONTENT}{/if}
      </div>
    {/foreach}
  {/if}
      </div>
    </div> <!-- #permitAction -->
    <div id="regenerationMsg" class="bulkAction" hidden>
        <div id="regenerationStatus">
          <span id="regenerationText">{'Generate multiple size images'|translate}</span>
          <span class="badge-number badge-number-fs-128"></span>
        </div>
        <input type="hidden" name="regenerateSuccess" value="0">
        <input type="hidden" name="regenerateError" value="0">
      </div>
    <!-- progress bar -->
    <div id="uploadingActions" hidden>
      <div class="big-progressbar">
        <div class="progressbar"></div>
      </div>
    </div>
  </fieldset>

  </form>

</div> <!-- #batchManagerGlobal -->
{include file='include/album_selector.inc.tpl'}

{combine_css path="themes/admin/_base/css/pages/batch_manager_global.css"}
