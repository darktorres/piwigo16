{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}

{footer_script}

const filters_names = {$search.filters_names|json_encode};

const _hide = function(id) { var el = document.getElementById(id); if (el) el.style.display = 'none'; };
const _show = function(id) { var el = document.getElementById(id); if (el) el.style.display = ''; };
const _el   = function(id) { return document.getElementById(id); };

for (const filter_name of filters_names) {
  var filterCb  = _el(filter_name + 'Filters');
  var selectEl  = _el('f' + filter_name + 'Select');
  var defaultCb = _el('default_' + filter_name);

  if (filterCb && !filterCb.checked) {
    _hide('f' + filter_name + 'Select');
    _hide(filter_name + 'Arrow');
    if (defaultCb && defaultCb.parentElement) defaultCb.parentElement.style.display = 'none';
  }

  if (selectEl && selectEl.value !== 'admins-only') {
    _hide(filter_name + 'AdminIcon');
  }

  if (defaultCb && defaultCb.checked && defaultCb.parentElement) {
    defaultCb.parentElement.classList.add('selected-filter-container');
  }

  if (filterCb) filterCb.addEventListener('click', function() {
    var cb  = _el(filter_name + 'Filters');
    var sel = _el('f' + filter_name + 'Select');
    var def = _el('default_' + filter_name);
    if (cb && cb.checked) {
      _show('f' + filter_name + 'Select');
      _show(filter_name + 'Arrow');
      if (def && def.parentElement) def.parentElement.style.display = '';
      if (sel && sel.value === 'admins-only') _show(filter_name + 'AdminIcon');
    } else {
      _hide('f' + filter_name + 'Select');
      _hide(filter_name + 'Arrow');
      _hide(filter_name + 'AdminIcon');
      if (def && def.parentElement) def.parentElement.style.display = 'none';
    }
  });

  if (selectEl) selectEl.addEventListener('click', function() {
    var sel = _el('f' + filter_name + 'Select');
    if (sel && sel.value === 'admins-only') _show(filter_name + 'AdminIcon');
    else _hide(filter_name + 'AdminIcon');
  });

  if (defaultCb) defaultCb.addEventListener('click', function() {
    var cb = _el('default_' + filter_name);
    var parent = cb && cb.parentElement;
    if (!parent) return;
    if (cb.checked) parent.classList.add('selected-filter-container');
    else parent.classList.remove('selected-filter-container');
  });
}

{/footer_script}

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
      <span class="icon-help-circled tiptip" title="{'This will be different for each user. Won\'t be applied for unregistered visitors'|translate}" style="cursor:help"></span>
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