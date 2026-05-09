{include file='include/autosize.inc.tpl'}
{include file='include/datepicker.inc.tpl'}


{combine_css path="themes/admin/_base/fontello/css/animation.css" order=10}
<script id="pwg-page-data" type="application/json">{$page_data_json}</script>
<script id="pwg-batch-manager-unit-data" type="application/json">{$batch_manager_unit_page_data_json}</script>

{combine_script id='batchManagerUnit' load='footer' path='themes/admin/_base/js/batchManagerUnit.js'}
<div id="batchManagerGlobal">
	<div class="u-clear-both"></div>
	{if isset($ELEMENT_IDS)}
	<div>
		<input type="hidden" name="element_ids" value="{$ELEMENT_IDS}">
	</div>
	{/if}
  {*Filters*}
	<form method="post" action="{$F_ACTION}">
		{include file='include/batch_manager_filter.inc.tpl'   title={'Batch Manager Filter'|translate}  searchPlaceholder={'Filters'|translate}}
	</form>
	<legend class="bm-list-legend">
		<span class='icon-menu icon-blue'></span>
		{'List'|translate}
		<span class="count-badge"> {count($all_elements)}</span>
	</legend>
	{if !empty($elements) }
	<div class="bm-pagination-row">
	  	<div class="pagination-per-page">
      		<span>{'photos per page'|translate} :</span>
    		<a href="{$U_ELEMENTS_PAGE}&amp;display=5" class="{if $per_page == 5}selected-pagination{/if}">5</a>
    		<a href="{$U_ELEMENTS_PAGE}&amp;display=10" class="{if $per_page == 10}selected-pagination{/if}">10</a>
    		<a href="{$U_ELEMENTS_PAGE}&amp;display=50" class="{if $per_page == 50}selected-pagination{/if}">50</a>
		</div>

		<div class="pagination-actions">
			<div class="pagination-reload">
				{if !empty($navbar) }
				<a class="button-reload tiptip" title="{'Pagination has changed and needs to be reloaded !'|translate}" hidden href="{$F_ACTION}"><i class="icon-cw"></i></a>
				{include file='navigation_bar.tpl'|get_extent:'navbar'}{/if}
			</div>
		</div>
	</div>
	{foreach from=$elements item=element}
	<div class="infos deleted-badge" data-image_id="{$element.ID}" hidden>
		<i class="icon-ok bm-deleted-icon"></i>
		<p>
			&nbsp;{'Image'|translate}&nbsp;
			<p class="u-text-bold">
				#{$element.ID} '{$element.FILE}'
			</p>
			&nbsp;{'was succesfully deleted'|translate}
		</p>
	</div>
	<fieldset class="elementEdit" id="picture-{$element.ID}" data-image_id="{$element.ID}" data-related-category-ids="{$element.related_category_ids|escape}">
		<div class="metasync-success badge-container" hidden>
			<div class="badge-succes">
				<i class="icon-ok"></i>
				{'Metadata sync complete'|translate}
			</div>
		</div>
		<div class="pictureIdLabel">
			#{$element.ID}
		</div>
		<div class="media-box">
			<img src="{$element.TN_SRC}" alt="imagename" class="media-box-embed {if $element.FORMAT}u-fit-width{else}u-fit-height{/if}">
			<div class="media-hover">
				<div class='picture-preview-actions'>
					<a class="preview-box icon-zoom-square tiptip" href="{$element.FILE_SRC}" title="{'Zoom'|translate}"></a>
					<a class="icon-download tiptip" href="{$element.U_DOWNLOAD}" title="{'Download'|translate}"></a>
					<a class="icon-signal tiptip" href="{$element.U_HISTORY}" title="{'Visit history'|translate}"></a>
					<a class="icon-pulse tiptip" href="{$element.U_ACTIVITY}" title="{'Activity'|translate}"></a>
					<a target="_blank" class="icon-pencil tiptip" href="{$element.U_EDIT}" title="{'Edit photo'|translate}"></a>
					{if !url_is_remote($element.PATH)}
					<a class="icon-arrows-cw tiptip action-sync-metadata" title="{'Synchronize metadata'|translate}"></a>
					<a class="icon-trash tiptip action-delete-picture" title="{'delete photo'|translate}"></a>
					{/if}  
				</div>
				  {if isset($element.U_JUMPTO)}
				<a class="see-out" href="{$element.U_JUMPTO}" >
					<p>
						<i class="icon-left-open"></i>
						{'Open in gallery'|translate}
					</p>
					  {else}
					<a class="see-out disabled" href="#" >
						<p class="" title="{'You don\'t have access to this photo'|translate}" >
							<i class="icon-left-open"></i>
							{'Open in gallery'|translate}
						</p>
						  {/if}
					</a>
				</div>
			</div>
			<div class="main-info-container">
				<div class="main-info-block">
					<div class='info-framed-icon bm-info-framed-icon-tight'>
						<i class='icon-picture'></i>
					</div>
					<span class="main-info-title" id="filename">{$element.FILE}</span>
					<span class="main-info-desc" id="dimensions">{$element.DIMENSIONS}</span>
					<span class="main-info-desc" id="filesize">{$element.FILESIZE}</span>
					<span class="main-info-desc">{$element.EXT}</span>
				</div>
				<div class="main-info-block">
					<div class='info-framed-icon bm-info-framed-icon-tight'>
						<span class='icon-calendar'></span>
					</div>
					<span class="main-info-title first-letter-capitalize">{$element.POST_DATE}</span>
					<span class="main-info-desc">{$element.AGE}</span>
					<span class="main-info-desc">{$element.ADDED_BY}</span>
					<span class="main-info-desc">{$element.STATS}</span>
				</div>
			</div>
			<div class="info-container">
				<div class="half-line-info-box">
					<strong>{'Title'|translate}</strong>
					<input type="text" name="name" id="name" value="{$element.NAME}">
				</div>
				<div class="calendar-box">
					<strong>{'Creation date'|translate}</strong>
					<input type="hidden" id="date_creation" name="date_creation-{$element.id}" value="{$element.DATE_CREATION}">
					<label class="calendar-input">
						<i class="icon-calendar"></i>
						<input type="text" data-datepicker="date_creation-{$element.id}" data-datepicker-unset="date_creation_unset-{$element.id}" readonly>
						<a href="#" class="icon-cancel-circled unset datepickerDelete" id="date_creation_unset-{$element.id}"></a>
					</label>
				</div>
				<div class="half-line-info-box">
					<strong>{'Author'|translate}</strong>
					<input type="text" name="author" id="author" value="{$element.AUTHOR}">
				</div>
				<div class="half-line-info-box">
					<div class="privacy-label-container">
						<strong>{'Who can see ?'|translate}</strong>
						<i>{'level of confidentiality'|translate}</i>
					</div>
					<select name="level" id="level" size="1">
						{html_options options=$level_options selected=$element.level_options_selected}
					</select>
				</div>
				<div class="full-line-tag-box" id="action_add_tags">
					<strong>{'Tags'|translate}</strong>
					<select id="tags" data-selectize="tags" data-value="{$element.TAGS|json_encode|escape:html}"placeholder="{'Type in a search term'|translate}"data-create="true" name="tags" id="tags-{$element.id}[]" multiple></select>
				</div>
				<div class="full-line-box" id="{$element.ID}">
					<strong>{'Linked albums'|translate} <span class="linked-albums-badge {if $element.related_categories|count < 1 } badge-red {/if}"> {$element.related_categories|count} </span></strong>
					{if $element.related_categories|count 
					< 1}
					<span class="orphan-photo">{'This photo is an orphan'|translate}</span>
					{else}
					<span class="orphan-photo"></span>
					{/if}
					<div class="related-categories-container">
						{foreach from=$element.related_categories item=cat_path key=key}
						<div class="breadcrumb-item">
							<span class="link-path">{$cat_path['name']}</span>
							{if $cat_path['unlinkable']}
							<span id={$key} class="icon-cancel-circled remove-item"></span>
							{else}
							<span id={$key} class="icon-help-circled help-item tiptip" title="{'This picture is physically linked to this album, you can\'t dissociate them'|translate}"></span>
							{/if}
						</div>
						{/foreach}
					</div>
					<div class="breadcrumb-item linked-albums add-item {if $element.related_categories|count < 1 } highlight {/if}">
						<span class="icon-plus-circled"></span>
						{'Add'|translate}
					</div>
				</div>
				<div class="full-line-description-box">
					<strong>{'Description'|translate}</strong>
					<textarea cols="50" rows="4" name="description" class="description-box" id="description">{$element.DESCRIPTION}</textarea>
				</div>
{if isset($PLUGINS_BATCH_MANAGER_UNIT_ELEMENT_SUBTEMPLATE)}
{foreach from=$PLUGINS_BATCH_MANAGER_UNIT_ELEMENT_SUBTEMPLATE item=PATH}
	{include file=$PATH}
{/foreach} 
{/if} 
				{* Plugins anchor 1 *}
				<div class="validation-container">
					<div class="save-button-container">
						<div class="buttonLike action-save-picture buttonSubmitLocal">
							<i class="icon-floppy"></i>
							{'Save'|translate}
						</div>
					</div>
					<div class="local-unsaved-badge badge-container" hidden>
						<div class="badge-unsaved">
							<i class="icon-attention"></i>
							{'You have unsaved changes'|translate}
						</div>
					</div>
					<div class="local-success-badge badge-container" hidden>
						<div class="badge-succes">
							<i class="icon-ok"></i>
							{'Changes saved'|translate}
						</div>
					</div>
					<div class="local-error-badge badge-container" hidden>
						<div class="badge-error">
							<i class="icon-cancel"></i>
							{'An error has occured'|translate}
						</div>
					</div>
				</div>
			</div>
		</fieldset>
		{/foreach}
		<div class="bm-pagination-row">
			<div class="pagination-per-page">
				<span>{'photos per page'|translate} :</span>
		  		<a href="{$U_ELEMENTS_PAGE}&amp;display=5" class="{if $per_page == 5}selected-pagination{/if}">5</a>
		  		<a href="{$U_ELEMENTS_PAGE}&amp;display=10" class="{if $per_page == 10}selected-pagination{/if}">10</a>
		  		<a href="{$U_ELEMENTS_PAGE}&amp;display=50" class="{if $per_page == 50}selected-pagination{/if}">50</a>
	  		</div>

	  		<div class="pagination-actions">
		  		<div class="pagination-reload">
		{if !empty($navbar) }
			  		<a class="button-reload tiptip" title="{'Pagination has changed and needs to be reloaded !'|translate}" hidden href="{$F_ACTION}"><i class="icon-cw"></i></a>
		{include file='navigation_bar.tpl'|get_extent:'navbar'}{/if}
		  		</div>
	  		</div>
  		</div>
		{/if}
		<div class="bottom-save-bar">
			<input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
			<div class="badge-container global-unsaved-badge" hidden>
				<div class="badge-unsaved">
					<i class="icon-attention"></i>
					<span id="unsaved-count"></span>
					 {'image(s) contains unsaved changes'|translate}
				</div>
			</div>
			<div class="badge-container global-succes-badge" hidden>
				<div class="badge-succes">
					<i class="icon-ok"></i>
					{'Changes saved'|translate}
				</div>
			</div>
			<div class="badge-container global-error-badge" hidden>
				<div class="badge-error">
					<i class="icon-cancel"></i>
					{'An error has occured'|translate}
				</div>
			</div>
			<div class="buttonLike action-save-global">
				<i class="icon-floppy"></i>
				{'Save all photos'|translate}
			</div>
		</div>
	</div>


{include file='include/album_selector.inc.tpl'}

{combine_css path="themes/admin/_base/css/pages/batch_manager_unit.css"}
