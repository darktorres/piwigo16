{include file='include/datepicker.inc.tpl' load_mode='async'}
{include file='include/add_album.inc.tpl' load_mode='async'}

{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}

{combine_script id='batchManagerGlobal' load='async' require='datepicker,addAlbum' path='admin/themes/default/js/batchManagerGlobal.js'}

<script id="pwg-batch-manager-global-data" type="application/json">{$batch_manager_global_page_data_json}</script>

{footer_script}
window.lang = {
	Cancel: '{'Cancel'|translate|escape:'javascript'}',
	deleteProgressMessage: "{'Deletion in progress'|translate|escape:'javascript'}",
	syncProgressMessage: "{'Synchronization in progress'|translate|escape:'javascript'}",
	AreYouSure: "{'Are you sure?'|translate|escape:'javascript'}",
  generateMsg: "{'Generate multiple size images'|@translate}"
};

var nb_thumbs_page = {$nb_thumbs_page};
var nb_thumbs_set = {$nb_thumbs_set};
var applyOnDetails_pattern = "{'on the %d selected photos'|@translate}";
window.all_elements = [{if !empty($all_elements)}{$all_elements|join:","}{/if}];

var selectedMessage_pattern = "{'%d of %d photos selected'|@translate}";
var selectedMessage_none = "{'No photo selected, %d photos in current set'|@translate}";
var selectedMessage_all = "{'All %d photos are selected'|@translate}";
window.str_add_alb_associate = "{"Add Album"|@translate}";
window.str_select_alb_associate = "{"Select an album"|@translate}";

function checkPermitAction() {
  var nbSelected = 0;
  var setSelectedEl = document.querySelector("input[name=setSelected]");
  if (setSelectedEl && setSelectedEl.checked) {
    nbSelected = nb_thumbs_set;
  }
  else {
    nbSelected = Array.from(document.querySelectorAll(".thumbnails input[type=checkbox]")).filter(function(el) { return el.checked; }).length;
  }

  var permitAction = document.getElementById("permitAction");
  var forbidAction = document.getElementById("forbidAction");
  if (nbSelected == 0) {
    if (permitAction) permitAction.style.display = 'none';
    if (forbidAction) forbidAction.style.display = '';
  }
  else {
    if (permitAction) permitAction.style.display = '';
    if (forbidAction) forbidAction.style.display = 'none';
  }

  var applyOnDetails = document.getElementById("applyOnDetails");
  if (applyOnDetails) applyOnDetails.textContent = sprintf(applyOnDetails_pattern, nbSelected);

  var selectedMessage = document.getElementById("selectedMessage");
  if (selectedMessage) {
    if (nbSelected == 0) {
      selectedMessage.textContent = sprintf(selectedMessage_none, nb_thumbs_set);
    }
    else if (nbSelected == nb_thumbs_set) {
      selectedMessage.textContent = sprintf(selectedMessage_all, nb_thumbs_set);
    }
    else {
      selectedMessage.textContent = sprintf(selectedMessage_pattern, nbSelected, nb_thumbs_set);
    }
  }
}

Array.from(document.querySelectorAll("[id^=action_]")).forEach(function(el) { el.style.display = 'none'; });

var selectActionEl = document.querySelector("select[name=selectAction]");
if (selectActionEl) {
  selectActionEl.addEventListener('change', function() {
    Array.from(document.querySelectorAll("[id^=action_]")).forEach(function(el) { el.style.display = 'none'; });

    var action = this.value;
    {* if (action == 'move') {
      action = 'associate';
    } *}

    var actionEl = document.getElementById("action_" + action);
    if (actionEl) actionEl.style.display = '';

    var applyActionBlock = document.getElementById("applyActionBlock");
    if (this.value != -1) {
      if (applyActionBlock) applyActionBlock.style.display = '';
    }
    else {
      if (applyActionBlock) applyActionBlock.style.display = 'none';
    }
    var confirmDel = document.getElementById("confirmDel");
    if (this.value == "delete" || this.value == "delete_derivatives") {
      if (confirmDel) confirmDel.style.visibility = "visible";
    } else {
      if (confirmDel) confirmDel.style.visibility = "hidden";
    }
  });
}

Array.from(document.querySelectorAll(".wrap1 label")).forEach(function(label) {
  label.addEventListener('click', function(event) {
    var setSelectedEl = document.querySelector("input[name=setSelected]");
    if (setSelectedEl) { setSelectedEl.checked = false; setSelectedEl.dispatchEvent(new Event('change')); }

    var li = this.closest("li");
    var checkbox = this.querySelector("input[type=checkbox]");

    if (checkbox && checkbox.checked) {
      if (li) li.classList.add("thumbSelected");
    }
    else {
      if (li) li.classList.remove('thumbSelected');
    }

    checkPermitAction();
  });
});

function selectPageThumbnails() {
  Array.from(document.querySelectorAll(".thumbnails label")).forEach(function(label) {
    var checkbox = label.querySelector("input[type=checkbox]");
    if (checkbox) { checkbox.checked = true; checkbox.dispatchEvent(new Event('change')); }
    var li = label.closest("li");
    if (li) li.classList.add("thumbSelected");
  });
}

var selectAllEl = document.getElementById("selectAll");
if (selectAllEl) {
  selectAllEl.addEventListener('click', function(e) {
    e.preventDefault();
    var setSelectedEl = document.querySelector("input[name=setSelected]");
    if (setSelectedEl) { setSelectedEl.checked = false; setSelectedEl.dispatchEvent(new Event('change')); }
    selectPageThumbnails();
    checkPermitAction();
  });
}

var selectNoneEl = document.getElementById("selectNone");
if (selectNoneEl) {
  selectNoneEl.addEventListener('click', function(e) {
    e.preventDefault();
    var setSelectedEl = document.querySelector("input[name=setSelected]");
    if (setSelectedEl) { setSelectedEl.checked = false; setSelectedEl.dispatchEvent(new Event('change')); }

    Array.from(document.querySelectorAll(".thumbnails label")).forEach(function(label) {
      var checkbox = label.querySelector("input[type=checkbox]");
      if (checkbox && checkbox.checked) {
        checkbox.checked = false;
        checkbox.dispatchEvent(new Event('change'));
      }
      var li = label.closest("li");
      if (li) li.classList.remove("thumbSelected");
    });
    checkPermitAction();
  });
}

var selectInvertEl = document.getElementById("selectInvert");
if (selectInvertEl) {
  selectInvertEl.addEventListener('click', function(e) {
    e.preventDefault();
    var setSelectedEl = document.querySelector("input[name=setSelected]");
    if (setSelectedEl) { setSelectedEl.checked = false; setSelectedEl.dispatchEvent(new Event('change')); }

    Array.from(document.querySelectorAll(".thumbnails label")).forEach(function(label) {
      var checkbox = label.querySelector("input[type=checkbox]");
      if (checkbox) {
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event('change'));
      }
      var li = label.closest("li");
      if (checkbox && checkbox.checked) {
        if (li) li.classList.add("thumbSelected");
      }
      else {
        if (li) li.classList.remove('thumbSelected');
      }
    });
    checkPermitAction();
  });
}

var selectSetEl = document.getElementById("selectSet");
if (selectSetEl) {
  selectSetEl.addEventListener('click', function(e) {
    e.preventDefault();
    selectPageThumbnails();
    var setSelectedEl = document.querySelector("input[name=setSelected]");
    if (setSelectedEl) { setSelectedEl.checked = true; setSelectedEl.dispatchEvent(new Event('change')); }
    checkPermitAction();
  });
}

var setSelectedEl = document.querySelector("input[name=setSelected]");
if (setSelectedEl) {
  setSelectedEl.addEventListener('change', function() {
    var wholeSet = document.querySelector('input[name=whole_set]');
    if (wholeSet) wholeSet.value = this.checked ? all_elements.join(',') : '';
  });
}

{*
  if the whole set is selected on page load (after a first action has been applied),
  trigger a change to make sure input[name=whole_set] is updated
*}
var setSelectedCheck = document.querySelector('input[name="setSelected"]');
if (setSelectedCheck && setSelectedCheck.checked) {
  setSelectedCheck.dispatchEvent(new Event('change'));
}

var confirmDeletionEl = document.querySelector("input[name=confirm_deletion]");
if (confirmDeletionEl) {
  confirmDeletionEl.addEventListener('change', function() {
    var errorsEl = document.querySelector("#confirmDel span.errors");
    if (errorsEl) errorsEl.style.visibility = "hidden";
  });
}

var applyActionBtn = document.getElementById('applyAction');
if (applyActionBtn) {
  applyActionBtn.addEventListener('click', function(e) {
    var action = document.querySelector('[name="selectAction"]').value;
    if (action == 'delete_derivatives') {
      var confirmDeletionEl = document.querySelector("#confirmDel input[name=confirm_deletion]");
      if (!confirmDeletionEl || !confirmDeletionEl.checked) {
        var errorsEl = document.querySelector("#confirmDel span.errors");
        if (errorsEl) errorsEl.style.visibility = "visible";
        e.preventDefault();
        return false;
      } else {
        return true;
      }
    }

    if (action != 'generate_derivatives' || derivatives.finished()) {
      return true;
    }

    Array.from(document.querySelectorAll('.bulkAction')).forEach(function(el) { el.style.display = 'none'; });

    derivatives.elements = [];
    var setSelectedEl = document.querySelector('input[name="setSelected"]');
    if (setSelectedEl && setSelectedEl.checked) {
      derivatives.elements = all_elements;
    } else {
      Array.from(document.querySelectorAll('.thumbnails input[type=checkbox]')).forEach(function(cb) {
        if (cb.checked) {
          derivatives.elements.push(cb.value);
        }
      });
    }

    var applyActionBlock = document.getElementById('applyActionBlock');
    if (applyActionBlock) applyActionBlock.style.display = 'none';
    var selectActionEl = document.querySelector('select[name="selectAction"]');
    if (selectActionEl) selectActionEl.style.display = 'none';
    Array.from(document.querySelectorAll('.permitActionListButton div')).forEach(function(el) { el.classList.add('hidden'); });
    var regenerationMsg = document.getElementById('regenerationMsg');
    if (regenerationMsg) regenerationMsg.style.display = '';

    progress_start();
    progress();
    getDerivativeUrls();
    e.preventDefault();
    return false;
  });
}

checkPermitAction();

var filterPrefilterEl = document.querySelector("select[name=filter_prefilter]");
if (filterPrefilterEl) {
  filterPrefilterEl.addEventListener('change', function() {
    var val = this.value;
    var emptyCaddie = document.getElementById("empty_caddie");
    var duplicatesOptions = document.getElementById("duplicates_options");
    var deleteOrphans = document.getElementById("delete_orphans");
    var syncMd5sum = document.getElementById("sync_md5sum");
    if (emptyCaddie) emptyCaddie.style.display = val == "caddie" ? '' : 'none';
    if (duplicatesOptions) duplicatesOptions.style.display = val == "duplicates" ? '' : 'none';
    if (deleteOrphans) deleteOrphans.style.display = val == "no_album" ? '' : 'none';
    if (syncMd5sum) syncMd5sum.style.display = val == "no_sync_md5sum" ? '' : 'none';
  });
}
{/footer_script}

{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}

<div id="batchManagerGlobal">
  <form action="{$F_ACTION}" method="post">
  <input type="hidden" name="start" value="{$START}">
  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
  {include file='include/batch_manager_filter.inc.tpl' 
  title={'Batch Manager Filter'|@translate}
  searchPlaceholder={'Filters'|@translate}
  }
  <fieldset>

    <legend><span class='icon-check icon-blue '></span>{'Selection'|@translate}</legend>

  {if !empty($thumbnails)}
  <p id="checkActions">
{if $nb_thumbs_set > $nb_thumbs_page}
    <a href="#" id="selectAll">{'The whole page'|@translate}</a>
    <a href="#" id="selectSet">{'The whole set'|@translate}</a>
{else}
    <a href="#" id="selectAll">{'All'|@translate}</a>
{/if}
    <a href="#" id="selectNone">{'None'|@translate}</a>
    <a href="#" id="selectInvert">{'Invert'|@translate}</a>

    <span id="selectedMessage"></span>

    <input type="checkbox" name="setSelected" style="display:none" {if count($selection) == $nb_thumbs_set}checked="checked"{/if}>
    <input type="hidden" name="whole_set" value="">
  </p>

	<ul class="thumbnails">
		{html_style}
UL.thumbnails SPAN.wrap2{ldelim}
  width: {$thumb_params->max_width()+2}px;
}
UL.thumbnails SPAN.wrap2 {ldelim}
  height: {$thumb_params->max_height()+25}px;
}
		{/html_style}
		{foreach from=$thumbnails item=thumbnail}
		{assign var='isSelected' value=$thumbnail.id|@in_array:$selection}
		<li{if $isSelected} class="thumbSelected"{/if}>
			<span class="wrap1">
				<label class="font-checkbox">
					<span class="icon-check"></span><input type="checkbox" name="selection[]" value="{$thumbnail.id}" {if $isSelected}checked="checked"{/if}>
					<span class="wrap2">
					<div class="actions">
            <a href="{$thumbnail.U_EDIT}" target="_blank" class="icon-pencil" title="{'Edit photo'|@translate}"></a>
            <a href="{$thumbnail.FILE_SRC}" class="preview-box icon-zoom-square" title="{'Zoom'|@translate}"></a>
          </div>
						{if $thumbnail.level > 0}
						<em class="levelIndicatorF" title="{'Who can see these photos?'|@translate} : ">{'Level %d'|@sprintf:$thumbnail.level|@translate}</em>
						{/if}
						<img src="{$thumbnail.thumb->get_url()}" alt="{$thumbnail.file}" title="{$thumbnail.TITLE|@escape:'html'}" {$thumbnail.thumb->get_size_htm()}>
					</span>
				</label>
			</span>
		</li>
		{/foreach}
	</ul>

  {if !empty($navbar) }
  <div class="batchManager-pagination">
    <div class="pagination-per-page">
      <span>{'display'|@translate}</span>
      <a href="{$U_DISPLAY}&amp;display=20">20</a>
      <a href="{$U_DISPLAY}&amp;display=50">50</a>
      <a href="{$U_DISPLAY}&amp;display=100">100</a>
      <a href="{$U_DISPLAY}&amp;display=all">{'all'|@translate}</a>
    </div>

    {include file='navigation_bar.tpl'|@get_extent:'navbar'}
  </div>
  {/if}

  {else}
  <div class="selectionEmptyBlock">{'No photo in the current set.'|@translate}</div>
  {/if}
  </fieldset>

  <fieldset id="action">

    <legend><span class='icon-cog icon-red'></span>{'Action'|@translate}</legend>
      <div id="forbidAction"{if count($selection) != 0} style="display:none"{/if}>{'No photos selected, no actions possible.'|@translate}</div>
      <div id="permitAction"{if count($selection) == 0} style="display:none"{/if}>
    
    <div class="permitActionListButton">
      <div>
        <select name="selectAction">
          <option value="-1">{'Choose an action'|@translate}</option>
          <option disabled="disabled">------------------</option>
          <option value="delete" class="icon-trash">{'Delete selected photos'|@translate}</option>
          <option value="associate">{'Associate to album'|@translate}</option>
          <option value="move">{'Move to album'|@translate}</option>
      {if !empty($associated_categories)}
          <option value="dissociate">{'Dissociate from album'|@translate}</option>
      {/if}
          <option value="add_tags">{'Add tags'|@translate}</option>
      {if !empty($associated_tags)}
          <option value="del_tags">{'remove tags'|@translate}</option>
      {/if}
          <option value="author">{'Set author'|@translate}</option>
          <option value="title">{'Set title'|@translate}</option>
          <option value="date_creation">{'Set creation date'|@translate}</option>
          <option value="level" class="icon-lock">{'Who can see these photos?'|@translate} ({'Privacy level'|translate})</option>
          <option value="metadata">{'Synchronize metadata'|@translate}</option>
      {if ($IN_CADDIE)}
          <option value="remove_from_caddie">{'Remove from caddie'|@translate}</option>
      {else}
          <option value="add_to_caddie">{'Add to caddie'|@translate}</option>
      {/if}
    		<option value="delete_derivatives">{'Delete multiple size images'|@translate}</option>
    		<option value="generate_derivatives">{'Generate multiple size images'|@translate}</option>
      {if !empty($element_set_global_plugins_actions)}
        {foreach from=$element_set_global_plugins_actions item=action}
          <option value="{$action.ID}">{$action.NAME}</option>
        {/foreach}
      {/if}
        </select>
      </div>
      <p id="confirmDel" style="visibility:hidden">
        <label class="font-checkbox">
          <span class="icon-check"></span>
          <input type="checkbox" name="confirm_deletion" value="1"> {'Are you sure?'|@translate}</input>
        </label><br/><br/>
        <span class="errors" style="visibility:hidden;margin:0;">{"You need to confirm deletion"|translate}</span>
      </p>
      <p id="applyActionBlock" style="display:none;margin:1em 0 0 0;" class="actionButtons">
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
        <select data-selectize="categories" data-default="" name="move" style="width:600px" placeholder="{'Select an album... or type it!'|@translate}"></select>
        <a href="#" data-add-album="move" title="{'create a new album'|@translate}" class="icon-plus"></a>
      </div>

      <!-- dissociate -->
      <div id="action_dissociate" class="bulkAction">
        <select data-selectize="categories" placeholder="{'Type in a search term'|translate}"
          name="dissociate" style="width:600px"></select>
      </div>


      <!-- add_tags -->
      <div id="action_add_tags" class="bulkAction">
        <select data-selectize="tags" data-create="true" placeholder="{'Type in a search term'|translate}"
          name="add_tags[]" multiple style="width:400px;"></select>
      </div>

      <!-- del_tags -->
      <div id="action_del_tags" class="bulkAction">
  {if !empty($associated_tags)}
        <select data-selectize="tags" name="del_tags[]" multiple style="width:400px;"
          placeholder="{'Type in a search term'|translate}">
        {foreach from=$associated_tags item=tag}
          <option value="{$tag.id}">{$tag.name}</option>
        {/foreach}
        </select>
  {/if}
      </div>

      <!-- author -->
      <div id="action_author" class="bulkAction">
      <label class="font-checkbox"><span class="icon-check"></span><input type="checkbox" name="remove_author"> {'remove author'|@translate}</label>
      <input type="text" class="large" name="author" placeholder="{'Type here the author name'|@translate}">
      </div>

      <!-- title -->
      <div id="action_title" class="bulkAction">
      <label class="font-checkbox"><span class="icon-check"></span><input type="checkbox" name="remove_title"> {'remove title'|@translate}</label>
      <input type="text" class="large" name="title" placeholder="{'Type here the title'|@translate}">
      </div>

      <!-- date_creation -->
      <div id="action_date_creation" class="bulkAction">
        <label class="font-checkbox"><span class="icon-check"></span><input type="checkbox" name="remove_date_creation"> {'remove creation date'|@translate}</label><br>
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
          <a href="javascript:selectGenerateDerivAll()">{'All'|@translate}</a>
          <a href="javascript:selectGenerateDerivNone()">{'None'|@translate}</a>
        </div>
        <br>
        {foreach from=$generate_derivatives_types key=type item=disp}
          <label class="font-checkbox"><span class="icon-check"></span><input type="checkbox" name="generate_derivatives_type[]" value="{$type}"> {$disp}</label>
        {/foreach}
      </div>

      <!-- delete derivatives -->
      <div id="action_delete_derivatives" class="bulkAction">
        <div class="deleteDerivButtons">
          <a href="javascript:selectDelDerivAll()">{'All'|@translate}</a>
          <a href="javascript:selectDelDerivNone()">{'None'|@translate}</a>
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
    <div id="regenerationMsg" class="bulkAction" style="display:none;margin-left:0;">
        <div id="regenerationStatus" style="margin-bottom:10px;">
          <span id="regenerationText">{'Generate multiple size images'|@translate}</span>
          <span class="badge-number" style="font-size:12.8px"></span>
        </div>
        <input type="hidden" name="regenerateSuccess" value="0">
        <input type="hidden" name="regenerateError" value="0">
      </div>
    <!-- progress bar -->
    <div id="uploadingActions" style="display:none">
      <div class="big-progressbar" style="max-width:100%;margin-bottom: 10px;">
        <div class="progressbar" style="width:0%"></div>
      </div>
    </div>
  </fieldset>

  </form>

</div> <!-- #batchManagerGlobal -->
{include file='include/album_selector.inc.tpl'}

{combine_css path="admin/themes/default/css/pages/batch_manager_global.css"}
