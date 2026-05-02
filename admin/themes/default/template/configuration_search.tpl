<script id="pwg-page-data" type="application/json">{$page_data_json}</script>
{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_script id='configuration_search' load='footer' require='common' path='admin/themes/default/js/configuration_search.js'}

{combine_css path="themes/default/vendor/fontello/css/gallery-icon.css" order=-10}

<form method="post" action="{$F_ACTION}" class="properties">

<div id="configContent">

  <fieldset class="searchConf">
    <legend><span class="icon-equalizer icon-blue rotate-element"></span>{'Filters'|translate}</legend>
    <ul>
      {foreach from=$search.filters_names item=filter_name}
      <li class="filters-grid">
        <label class="font-checkbox">
          <span class="icon-check"></span>
          <input type="checkbox" class="filters-icon-check" name="filters_views_box[{$filter_name}]" id="{$filter_name}Filters" 
          {if ($search.filters_views.$filter_name.access != 'nobody' and !($filter_name == 'rating' and !$SHOW_FILTER_RATINGS))}
            checked="checked"
          {/if} 
          {if ($filter_name == 'rating' and !$SHOW_FILTER_RATINGS)}
            disabled
          {/if}
          >
          {if $filter_name == 'words'}
            {'Search for words'|translate}
          {else if $filter_name == 'expert'}
            {'Expert mode'|translate}
          {else if $filter_name == 'file_size'}
            {'Filesize'|translate}
          {else}
            {ucfirst(str_replace('_', ' ', $filter_name))|translate}
          {/if}
        </label>
        <div class='select-views-arrow icon-down-open' id="{$filter_name}Arrow"> </div>
        <select name="filters_views[{$filter_name}][access]" id="f{$filter_name}Select" class="select-views">
          <option value="everybody" {if ($search.filters_views.$filter_name.access == "everybody")}selected{/if}>{'Everybody'|translate}</option>
          <option value="registered-users" {if ($search.filters_views.$filter_name.access == "registered-users")}selected{/if}>{'registered users'|translate|ucfirst}</option>
          <option value="admins-only" id="{$filter_name}Admin" {if ($search.filters_views.$filter_name.access == "admins-only")}selected{/if}>{'Admins only'|translate}</option>
        </select>
        <div class='icon-users select-views-admin' id="{$filter_name}AdminIcon"> </div>
      </li>
      {/foreach}
    </ul>
  </fieldset>

  <fieldset class="searchConf">
    <legend><span class="icon-equalizer icon-green rotate-element"></span>{'Default filters'|translate}</legend>

    <div class="last-filters">
      <label class="font-checkbox">
        <span class="icon-check"></span>
        <input type="checkbox" class="filters-icon-check" name="filters_views[last_filters_conf]" id="lastFilters" {if ($search.filters_views.last_filters_conf)}checked="checked"{/if}>
        {'Set last used filters as default for each users'|translate}
      </label>
      <span class="icon-help-circled tiptip" title="{'This will be different for each user. Won\'t be applied for unregistered visitors'|translate}"></span>
    </div>

    <label class="filter-manager-options-container">
      <span class="mcs-icon-options gallery-icon-search">{'Search for words'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[words][default]" id="default_words" 
      {if ($search.filters_views.words.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options gallery-icon-tag">{'Tags'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[tags][default]" id="default_tags" 
      {if ($search.filters_views.tags.default)}
        checked="checked"
        {/if} 
        hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options gallery-icon-calendar-plus">{'Post date'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[post_date][default]" id="default_post_date" 
      {if ($search.filters_views.post_date.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options gallery-icon-calendar">{'Creation date'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[creation_date][default]" id="default_creation_date" 
      {if ($search.filters_views.creation_date.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options gallery-icon-album">{'Album'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[album][default]" id="default_album" 
      {if ($search.filters_views.album.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options gallery-icon-user-edit">{'Author'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[author][default]" id="default_author" 
      {if ($search.filters_views.author.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options icon-user-1">{'Added by'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[added_by][default]" id="default_added_by" 
      {if ($search.filters_views.added_by.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options icon-file-image">{'File type'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[file_type][default]" id="default_file_type" 
      {if ($search.filters_views.file_type.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options icon-crop">{'Ratio'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[ratio][default]" id="default_ratio" 
      {if ($search.filters_views.ratio.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options icon-star">{'Rating'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[rating][default]" id="default_rating" 
      {if ($search.filters_views.rating.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options icon-hdd">{'Filesize'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[file_size][default]" id="default_file_size" 
      {if ($search.filters_views.file_size.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options gallery-icon-height">{'Height'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[height][default]" id="default_height" 
      {if ($search.filters_views.height.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options gallery-icon-width">{'Width'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[width][default]" id="default_width" 
      {if ($search.filters_views.width.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
    <label class="filter-manager-options-container">
      <span class="mcs-icon-options gallery-icon-chemistry">{'Expert mode'|translate}</span>
      <input type="checkbox" class="filter-manager-options-check" name="filters_views[expert][default]" id="default_expert" 
      {if ($search.filters_views.expert.default)}
        checked="checked"
      {/if} 
      hidden/>
    </label>
  </fieldset>

</div>

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
      <button class="buttonLike"  type="submit" name="submit" {if $isWebmaster != 1}disabled{/if}><i class="icon-floppy"></i> {'Save Settings'|@translate}</button>
    </div>
  </div>
  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
</div>

</form>