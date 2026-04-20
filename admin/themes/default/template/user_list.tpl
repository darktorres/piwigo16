{combine_css path='admin/themes/default/css/pages/user_list.css' order=-10}
<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

{combine_css path='node_modules/tom-select/dist/css/tom-select.default.css'}
{combine_css path='node_modules/nouislider/dist/nouislider.min.css'}
{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}

{if $vite_user_list}
<script type="module" src="admin/themes/default/js/dist/{$vite_user_list}"></script>
{/if}

<div class="selection-mode-group-manager" style="right:30px">
  <label class="switch">
    <input type="checkbox" id="toggleSelectionMode">
    <span class="slider round"></span>
  </label>
  <p>{'Selection mode'|translate}</p>
</div>

<div id="user-table">
  <div id="user-table-content">
    <div class="user-manager-header">

      <div class="UserViewSelector">
        <input type="radio" name="layout" class="switchLayout" id="displayCompact"
          {if $view_selector == 'compact'}checked{/if} /><label for="displayCompact"><span
            class="icon-th-large firstIcon tiptip" title="{'Compact View'|translate}"></span></label><input type="radio"
          name="layout" class="switchLayout tiptip" id="displayLine" {if $view_selector == 'line'}checked{/if} /><label
          for="displayLine"><span class="icon-th-list tiptip" title="{'Line View'|translate}"></span></label><input
          type="radio" name="layout" class="switchLayout" id="displayTile"
          {if $view_selector == 'tile'}checked{/if} /><label for="displayTile"><span class="icon-pause lastIcon tiptip"
            title="{'Tile View'|translate}"></span></label>
      </div>

      <div style="display:flex;justify-content:space-between; flex-grow:1;">
        <div style="display:flex; align-items: center;">
          <div class="not-in-selection-mode user-header-button add-user-button" style="margin: auto;">
            <label class="head-button-2 icon-plus-circled">
              <p>{'Add a user'|translate}</p>
            </label>
          </div>

          <div class="not-in-selection-mode user-header-button" style="margin: auto;">
            <label class="head-button-2 icon-user-secret edit-guest-user-button">
              <p>{'Edit guest user'|translate}</p>
            </label>
          </div>
          <div id="AddUserSuccess">
            <label class="icon-ok">
              <span>{'New user added'|translate}</span><span class="icon-pencil edit-now">{'Edit'|translate}</span>
            </label>
          </div>
          <div class="in-selection-mode">
            <div id="checkActions">
              <span>{'Select'|translate}</span>
              <a href="#" id="selectAllPage">{'The whole page'|translate}</a>
              <a href="#" id="selectSet">{'The whole set'|translate}</a><span class="loading" style="display:none"><img
                  src="themes/default/images/ajax-loader-small.gif"></span>
              <a href="#" id="selectNone">{'None'|translate}</a>
              <a href="#" id="selectInvert">{'Invert'|translate}</a>
              <span id="selectedMessage"></span>
            </div>
          </div>
        </div>
        <div style="display:flex; width: 270px;">
        </div>
      </div>
      <div class="not-in-selection-mode" style="width: 264px; height:2px">
      </div>
    </div>
    <div class="filtered-users"></div>
    <div class="advanced-filter-btn icon-filter">
      <span>{'Filters'|translate}</span>
      <span class="filter-counter"></span>
    </div>
    <div id='search-user'>
      <div class='search-info'> </div>
      {*This input (#user_search2) is used to bait the chrome autocomplete tool. It is hidden in navigator and is not meant to be seen.*}
      <input id="user_search2" class='search-input2' type='text' placeholder='{'Search'|translate}'>
      <span class='icon-search search-icon'> </span>
      <span class="icon-cancel search-cancel"></span>
      <input id="user_search" class='search-input' type='text' placeholder='{'Search'|translate}'>
    </div>
    <div class="advanced-filter">
      <div class="advanced-filter-header">
        <span class="advanced-filter-title">{'Advanced filters'|translate}</span>
        <span class="advanced-filter-close icon-cancel"></span>
      </div>
      <div class="advanced-filter-container">
        <div class="advanced-filter-status advanced-filter-item">
          <label class="advanced-filter-item-label">{'Status'|translate}</label>
          <div class="advanced-filter-select-container advanced-filter-item-container">
            <select class="user-action-select advanced-filter-select" name="filter_status">
              <option value="" label="" selected></option>
              {html_options options=$pref_status_options}
            </select>
          </div>
        </div>
        <div class="advanced-filter-level advanced-filter-item">
          <label class="advanced-filter-item-label">{'Privacy level'|translate}</label>
          <div class="advanced-filter-select-container advanced-filter-item-container">
            <select class="user-action-select advanced-filter-select" name="filter_level" size="1">
              <option value="" label="" selected></option>
              {html_options options=$level_options}
            </select>
          </div>
        </div>
        <div class="advanced-filter-group advanced-filter-item">
          <label class="advanced-filter-item-label">{'Group'|translate}</label>
          <div class="advanced-filter-select-container advanced-filter-item-container">
            <select class="user-action-select advanced-filter-select" name="filter_group">
              <option value="" label="" selected></option>
              {html_options options=$association_options}
            </select>
          </div>
        </div>
        <div class="advanced-filter-date advanced-filter-item">
          <div class="advanced-filter-date-title" style="display:flex">
            <span class="advanced-filter-item-label">{'Registered'|translate}</span>
            <span class='dates-infos'></span>
          </div>
          <div class="dates-select-bar">
            <div class="slider-bar-wrapper">
              <div class="slider-bar-container"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="user-container-header">
      <!-- edit / select -->
      <div class="user-header-col user-header-select no-flex-grow">
      </div>
      <!-- icon -->
      <div class="user-header-col user-header-initials no-flex-grow">
      </div>
      <!-- username -->
      <div class="user-header-col user-header-username">
        <span>{'Username'|translate}</span>
      </div>
      <!-- status -->
      <div class="user-header-col user-header-status">
        <span>{'Status'|translate}</span>
      </div>
      <!-- email address -->
      <div class="user-header-col user-header-email not-in-selection-mode">
        <span>{'Email Address'|translate}</span>
      </div>
      {* <!-- groups -->
      <div class="user-header-col user-header-groups">
        <span>{'Groups'|translate}</span>
      </div> *}
      <!-- registration date -->
      <div class="user-header-col user-header-registration">
        <span>{'Registered'|translate}</span>
      </div>
      <!-- groups -->
      <div class="user-header-col user-header-groups">
        <span>{'Groups'|translate}</span>
      </div>
    </div>
    <div class="user-update-spinner icon-spin6 animate-spin"></div>
    <div class="user-container-wrapper">
    </div>
    <!-- Pagination -->
    <div class="user-pagination">
      <div class="pagination-per-page">
        <span class="thumbnailsActionsShow" style="font-weight: bold;">{'Display'|translate}</span>
        <a id="pagination-per-page-5">5</a>
        <a id="pagination-per-page-10">10</a>
        <a id="pagination-per-page-25">25</a>
        <a id="pagination-per-page-50">50</a>
      </div>

      <div class="pagination-container">
        <div class="pagination-arrow left">
          <span class="icon-left-open"></span>
        </div>
        <div class="pagination-item-container">
        </div>
        <div class="user-update-spinner icon-spin6 animate-spin"></div>
        <div class="pagination-arrow right">
          <span class="icon-left-open"></span>
        </div>
      </div>
    </div>
  </div>
  <div id="selection-mode-block" class="in-selection-mode tag-selection"
    style="width: 250px; min-width:250px;display: block;position:relative">
    <div class="user-selection-content">
      <div class="selection-mode-ul">
        <p>{'Your selection'|translate}</p>
        <div class="user-selected-list">
        </div>
        <div class="selection-other-users"></div>
      </div>
      <fieldset id="action">
        <legend>{'Action'|translate}</legend>

        <div id="forbidAction">{'No users selected, no actions possible.'|translate}</div>
        <div id="permitActionUserList" style="display:block">

          <div class="user-action-select-container">
            <select class="user-action-select" name="selectAction">
              <option value="-1">{'Choose an action'|translate}</option>
              <optgroup label="Actions">
                <option value="delete" class="icon-trash">{'Delete selected users'|translate}</option>
                <option value="status">{'Status'|translate}</option>
                <option value="group_associate">{'associate to group'|translate}</option>
                <option value="group_dissociate">{'dissociate from group'|translate}</option>
                <option value="enabled_high">{'High definition enabled'|translate}</option>
                <option value="level">{'Privacy level'|translate}</option>
                <option value="nb_image_page">{'Number of photos per page'|translate}</option>
                <option value="theme">{'Theme'|translate}</option>
                <option value="language">{'Language'|translate}</option>
                <option value="recent_period">{'Recent period'|translate}</option>
                <option value="expand">{'Expand all albums'|translate}</option>
                {if $ACTIVATE_COMMENTS}
                  <option value="show_nb_comments">{'Show number of comments'|translate}</option>
                {/if}
                <option value="show_nb_hits">{'Show number of hits'|translate}</option>
              </optgroup>
            </select>
          </div>
          {* delete *}
          <div id="action_delete" class="bulkAction">
            <div class="user-list-checkbox" name="confirm_deletion">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'Are you sure?'|translate}</span>
            </div>
          </div>

          {* status *}
          <div id="action_status" class="bulkAction">
            <div class="user-action-select-container">
              <select class="user-action-select" name="status">
                {html_options options=$pref_status_options selected=$pref_status_selected}
              </select>
            </div>
          </div>

          {* group_associate *}
          <div id="action_group_associate" class="bulkAction">
            <div class="user-action-select-container">
              <select class="user-action-select" name="associate">
                {html_options options=$association_options}
              </select>
            </div>
          </div>

          {* group_dissociate *}
          <div id="action_group_dissociate" class="bulkAction">
            <div class="user-action-select-container">
              <select class="user-action-select" name="dissociate">
                {html_options options=$association_options}
              </select>
            </div>
          </div>

          {* enabled_high *}
          <div id="action_enabled_high" class="bulkAction yes_no_radio">
            <span class="user-list-checkbox" name="enabled_high_yes">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'Yes'|translate}</span>
            </span>
            <span class="user-list-checkbox" data-selected="1" name="enabled_high_no">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'No'|translate}</span>
            </span>
          </div>

          {* level *}
          <div id="action_level" class="bulkAction">
            <div class="user-action-select-container">
              <select class="user-action-select" name="level" size="1">
                {html_options options=$level_options selected=$level_selected}
              </select>
            </div>
          </div>

          {* nb_image_page *}
          <div id="action_nb_image_page" class="bulkAction">
            <div class="user-property-label photos-select-bar">{'Photos per page'|translate}
              <br />
              <span class="nb-img-page-infos"></span>
              <div class="slider-bar-wrapper">
                <div class="slider-bar-container"></div>
              </div>
              <input name="nb_image_page" />
            </div>
          </div>

          {* theme *}
          <div id="action_theme" class="bulkAction">

            <div class="user-action-select-container">
              <select class="user-action-select" name="theme" size="1">
                {html_options options=$theme_options selected=$theme_selected}
              </select>
            </div>
          </div>

          {* language *}
          <div id="action_language" class="bulkAction">
            <div class="user-action-select-container">
              <select class="user-action-select" name="language" size="1">
                {html_options options=$language_options selected=$language_selected}
              </select>
            </div>
          </div>

          {* recent_period *}
          <div id="action_recent_period" class="bulkAction">
            <div class="user-property-label period-select-bar">{'Recent period'|translate}
              <br />
              <span class="recent_period_infos"></span>
              <div class="slider-bar-wrapper">
                <div class="slider-bar-container"></div>
              </div>
            </div>
          </div>

          {* expand *}
          <div id="action_expand" class="bulkAction yes_no_radio">
            <span class="user-list-checkbox" name="expand_yes">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'Yes'|translate}</span>
            </span>
            <span class="user-list-checkbox" data-selected="1" name="expand_no">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'No'|translate}</span>
            </span>
          </div>

          {* show_nb_comments *}
          <div id="action_show_nb_comments" class="bulkAction yes_no_radio">
            <span class="user-list-checkbox" name="show_nb_comments_yes">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'Yes'|translate}</span>
            </span>
            <span class="user-list-checkbox" data-selected="1" name="show_nb_comments_no">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'No'|translate}</span>
            </span>
          </div>

          {* show_nb_hits *}
          <div id="action_show_nb_hits" class="bulkAction yes_no_radio">
            <span class="user-list-checkbox" name="show_nb_hits_yes">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'Yes'|translate}</span>
            </span>
            <span class="user-list-checkbox" data-selected="1" name="show_nb_hits_no">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'No'|translate}</span>
            </span>
          </div>

          <p id="applyActionBlock" style="display:none" class="actionButtons">
            <input id="applyAction" class="submit" type="submit" value="{'Apply action'|translate}" name="submit">
            <span id="applyOnDetails"></span></input>
            <span id="applyActionLoading" style="display:none"><img
                src="themes/default/images/ajax-loader-small.gif"></span>
            <br />
            <span class="infos icon-ok icon-green"
              style="display:inline-block;display:none;max-width:100%;margin:0;margin-top:30px;min-height:0;">{'Users modified'|translate}</span>
          </p>
        </div> {* #permitActionUserList *}
      </fieldset>
    </div>
  </div>
</div>

<!-- User container template -->
<div id="template">
  <div class="user-container">
    <!-- edit-v1 -->
    <div class="user-col user-container-select tmp-select in-selection-mode user-first-col no-flex-grow">
      <div class="user-container-checkbox user-list-checkbox" name="select_container">
        <span class="select-checkbox">
          <i class="icon-ok"></i>
        </span>
      </div>
    </div>
    <div class="user-col user-container-edit tmp-edit not-in-selection-mode user-first-col no-flex-grow">
      <span class="icon-pencil"></span>
    </div>
    <div class="user-col user-container-initials no-flex-grow">
      <div class="user-container-initials-wrapper">
        <span>
          <!-- initials -->
        </span>
      </div>
    </div>
    <div class="user-col user-container-username">
      <span>
        <!-- name -->
      </span>
    </div>
    <div class="user-col user-container-status">
      <span>
        <!-- status -->
      </span>
    </div>
    <div class="user-col user-container-email not-in-selection-mode">
      <span>
        <!-- email -->
      </span>
    </div>
    {* <div class="user-col user-container-groups">
      <!-- groups -->
    </div> *}
    <div class="user-col user-container-registration">
      <div>
        {* <span class="icon-clock registration-clock"></span> *}
        <div class="user-container-registration-info-wrapper">
          {* <span class="user-container-registration-date"><b><!-- date DD/MM/YY --></b></span>
          <span class="user-container-registration-time"><!-- time HH:mm:ss --></span> *}
          <span class="user-container-registration-date-since">
            <!-- date_since -->
          </span>
        </div>
      </div>
    </div>
    <div class="user-col user-container-groups">
      <!-- groups -->
    </div>
  </div>
  <span class="user-groups group-primary"></span>
  <span class="user-groups group-bonus"></span>
  <div class="user-selected-item">
    <a class="icon-cancel"></a>
    <p></p>
  </div>
</div>

<div id="UserList" class="UserListPopIn">

  <div class="UserListPopInContainer">

    <a class="icon-cancel CloseUserList"></a>
    <div class="summary-properties-update-container">
      <div class="summary-properties-container">
        <div class="summary-container">
          <div class="user-property-initials">
            <div>
              <span class="icon-blue">
                <!-- Initials (JP) -->
              </span>
            </div>
          </div>
          <div class="user-property-username">
            <span class="edit-username-title">
              <!-- Name (Jessy Pinkman) -->
            </span>
            <span class="edit-username-specifier">
              <!-- You specifier (you) -->
            </span>
            <span class="edit-username icon-pencil"></span>
          </div>
          <div class="user-property-username-change">
            <div class="summary-input-container">
              <input class="user-property-input user-property-input-username" value=""
                placeholder="{'Username'|translate}" />
            </div>
            <span class="icon-ok edit-username-validate"></span>
            <span class="icon-cancel-circled edit-username-cancel"></span>
          </div>
          <div class="user-property-password-container">
            <div class="user-property-password edit-password">
              <p class="user-property-button"><span class="icon-key user-edit-icon">
                </span>{'Change Password'|translate}</p>
            </div>
            <div class="user-property-password-change">
              <div class="summary-input-container">
                <input class="user-property-input user-property-input-password" value=""
                  placeholder="{'Password'|translate}" />
              </div>
              <span class="icon-ok edit-password-validate"></span>
              <span class="icon-cancel-circled edit-password-cancel"></span>
            </div>
            <div class="user-property-permissions">
              <p class="user-property-button"> <span class="icon-lock user-edit-icon"> </span><a
                  href="#">{'Permissions'|translate}</a></p>
            </div>
            <div class="user-stats">
              <div class="user-property-history">
                <p class="user-property-button"> <span class="icon-signal user-edit-icon"> </span><a
                    href="">{'Visit history'|translate}</a></p>
              </div>
            </div>
          </div>
          <div class="user-property-register-visit">
            <span class="user-property-register">
              <!-- Registered date XX/XX/XXXX -->
            </span>
            <span class="icon-calendar"></span>
            <span class="user-property-last-visit">
              <!-- Last Visit date XX/XX/XXXX -->
            </span>
          </div>
        </div>
        <div class="properties-container">
          <div class="user-property-column-title">
            <p>{'Properties'|translate}</p>
          </div>
          <div class="user-property-email">
            <p class="user-property-label">{'Email Address'|translate}</p>
            <input type="text" class="user-property-input" value="contact@jessy-pinkman.com" disabled="false" />
          </div>
          <div class="user-property-status">
            <p class="user-property-label">{'Status'|translate}
              <span class="icon-help-circled" title="<div class='tooltip-status-content'>
                    <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_webmaster'|translate}</span><span class='tooltip-col2'>{'Has access to all administration functionalities. Can manage both configuration and content.'|translate}</span></div>
                    <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_admin'|translate}</span><span class='tooltip-col2'>{'Has access to administration. Can only manage content: photos/albums/users/tags/groups.'|translate}</span></div>
                    <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_normal'|translate}</span><span class='tooltip-col2'>{'No access to administration, can see private content with appropriate permissions.'|translate}</span></div>
                    <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_generic'|translate}</span><span class='tooltip-col2'>{'Can be shared by several individuals without conflict (they cannot change the password).'|translate}</span></div>
                    <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_guest'|translate}</span><span class='tooltip-col2'>{'Equivalent to deactivation. The user is still in the list, but can no longer log in.'|translate}</span></div>
                  </div">
              </span>
            </p>
            <div class="user-property-select-container">
              <select name="status" class="user-property-select">
                <option value="webmaster">{'user_status_webmaster'|translate}</option>
                <option value="admin">{'user_status_admin'|translate}</option>
                <option value="normal">{'user_status_normal'|translate}</option>
                <option value="generic">{'user_status_generic'|translate}</option>
                <option value="guest">{'user_status_guest'|translate} ({'Deactivated'|translate})</option>
              </select>
            </div>
          </div>
          <div class="user-property-level">
            <p class="user-property-label">{'Privacy level'|translate}</p>
            <div class="user-property-select-container">
              <select name="privacy" class="user-property-select">
                <option value="0">{'Level 0'|translate}</option>
                <option value="1">{'Level 1'|translate}</option>
                <option value="2">{'Level 2'|translate}</option>
                <option value="4">{'Level 4'|translate}</option>
                <option value="8">{'Level 8'|translate}</option>
              </select>
            </div>
          </div>
          <div class="user-property-group-container">
            <p class="user-property-label">{'Groups'|translate}</p>
            <div class="user-property-select-container user-property-group">
              <select class="user-property-select" data-selectize="groups"
                placeholder="{'Select groups or type them'|translate}" name="group_id[]" multiple
                style="box-sizing:border-box;"></select>
            </div>
          </div>

          <div class="user-list-checkbox" name="hd_enabled">
            <span class="select-checkbox">
              <i class="icon-ok"></i>
            </span>
            <span class="user-list-checkbox-label">{'High definition enabled'|translate}</span>
          </div>
        </div>
      </div>
      <div class="update-container" style="display:flex;flex-direction:column">
        <div style="display:flex;justify-content:space-between;margin-bottom: 10px;">
          <div>
            <span class="update-user-button"><i class='icon-floppy'></i>{'Update'|translate}</span>
            <span class="close-update-button">{'Close'|translate}</span>
            <span class="update-user-success icon-green icon-ok">{'User updated'|translate}</span>
            <span class="update-user-fail icon-cancel"></span>
          </div>
          <div>
            <span class="delete-user-button icon-trash">{'Delete'|translate}</span>
          </div>
        </div>
        <div>
        </div>
      </div>
    </div>
    <div class="preferences-container">
      <div class="user-property-column-title">
        <p>{'Preferences'|translate}</p>
      </div>
      <div class="user-property-label photos-select-bar">{'Photos per page'|translate}
        <span class="nb-img-page-infos"></span>
        <div class="slider-bar-wrapper">
          <div class="slider-bar-container"></div>
        </div>
        <input name="recent_period" />
      </div>
      <div class="user-property-theme" style="margin-top: 37px;">
        <p class="user-property-label">{'Theme'|translate}</p>
        <div class="user-property-select-container">
          <select name="privacy" class="user-property-select">
            {html_options options=$theme_options selected=$theme_selected}
          </select>
        </div>
      </div>
      <div class="user-property-lang">
        <p class="user-property-label">{'Language'|translate}</p>
        <div class="user-property-select-container">
          <select name="privacy" class="user-property-select">
            {html_options options=$language_options selected=$language_selected}
          </select>
        </div>
      </div>
      <div class="user-property-label period-select-bar">{'Recent period'|translate}
        <span class="recent_period_infos"></span>
        <div class="slider-bar-wrapper">
          <div class="slider-bar-container"></div>
        </div>
      </div>

      <div class="user-list-checkbox" name="expand_all_albums">
        <span class="select-checkbox">
          <i class="icon-ok"></i>
        </span>
        <span class="user-list-checkbox-label">{'Expand all albums'|translate}</span>
      </div>
      <div class="user-list-checkbox" name="show_nb_comments">
        <span class="select-checkbox">
          <i class="icon-ok"></i>
        </span>
        <span class="user-list-checkbox-label">{'Show number of comments'|translate}</span>
      </div>
      <div class="user-list-checkbox" name="show_nb_hits">
        <span class="select-checkbox">
          <i class="icon-ok"></i>
        </span>
        <span class="user-list-checkbox-label">{'Show number of hits'|translate}</span>
      </div>
    </div>
  </div>
</div>

<div id="GuestUserList" class="UserListPopIn">

  <div class="GuestUserListPopInContainer">

    <a class="icon-cancel CloseUserList CloseGuestUserList"></a>
    <div id="guest-msg"
      style="background-color:#B9E2F8;padding:5;border-left:3px solid blue;display:flex;align-items:center;margin-bottom:30px">
      <span class="icon-info-circled-1" style="background-color:#B9E2F8;color:#26409D;font-size:3em"></span><span
        style="font-size:1.1em;color:#26409D;font-weight:bold;">{'Users not logged in will have these settings applied, these settings are used by default for new users'|translate}</span>
    </div>
    <div style='display:flex;'>
      <div class="summary-properties-update-container">
        <div class="summary-properties-container">
          <div class="summary-container">
            <div class="user-property-initials">
              <div>
                <span class="icon-blue"><i class="icon-user-secret"> </i></span>
              </div>
            </div>
            <div class="user-property-username">
              <span class="edit-username-title">
                <!-- name -> Jessy Pinkman -->
              </span>
              <span class="edit-username-specifier">
                <!-- you specifier(you) -->
              </span>
            </div>
            <div class="user-property-username-change">
              <div class="summary-input-container">
                <input class="user-property-input user-property-input-username" value=""
                  placeholder="{'Username'|translate}" />
              </div>
              <span class="icon-ok edit-username-validate"></span>
              <span class="icon-cancel-circled edit-username-cancel"></span>
            </div>
            <div class="user-property-password-container">
              <div class="user-property-password edit-password">
                <p class="user-property-button unavailable"><span
                    class="icon-key user-edit-icon"></span>{'Change Password'|translate}</p>
              </div>
              <div class="user-property-password-change">
                <div class="summary-input-container">
                  <input class="user-property-input user-property-input-password" value=""
                    placeholder="{'Password'|translate}" />
                </div>
                <span class="icon-ok edit-password-validate"></span>
                <span class="icon-cancel-circled edit-password-cancel"></span>
              </div>
              <div class="user-property-permissions">
                <p class="user-property-button"><span class="icon-lock user-edit-icon"></span><a
                    href="admin.php?page=user_perm&user_id={$guest_id}">{'Permissions'|translate}</a></p>
              </div>
            </div>
          </div>
          <div class="properties-container">
            <div class="user-property-column-title">
              <p>{'Properties'|translate}</p>
            </div>
            <div class="user-property-email">
              <p class="user-property-label">{'Email Address'|translate}</p>
              <input type="text" class="user-property-input" value="N/A" readonly />
            </div>
            <div class="user-property-status">
              <p class="user-property-label">{'Status'|translate}</p>
              <div class="user-property-select-container notClickableBefore">
                <select name="status" class="user-property-select notClickable">
                  <option value="guest">{'Guest'|translate}</option>
                </select>
              </div>
            </div>
            <div class="user-property-level">
              <p class="user-property-label">{'Privacy Level'|translate}</p>
              <div class="user-property-select-container">
                <select name="privacy" class="user-property-select">
                  <option value="0">{'Level 0'|translate}</option>
                  <option value="1">{'Level 1'|translate}</option>
                  <option value="2">{'Level 2'|translate}</option>
                  <option value="4">{'Level 4'|translate}</option>
                  <option value="8">{'Level 8'|translate}</option>
                </select>
              </div>
            </div>
            <div class="user-property-group-container">
              <p class="user-property-label">{'Groups'|translate}</p>
              <div class="user-property-select-container user-property-group">
                <select class="user-property-select" data-selectize="groups"
                  placeholder="{'Select groups or type them'|translate}" name="group_id[]" multiple
                  style="box-sizing:border-box;"></select>
              </div>
            </div>

            <div class="user-list-checkbox" name="hd_enabled">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'High definition enabled'|translate}</span>
            </div>
          </div>
        </div>
        <div class="update-container">
          <div style="display:flex;flex-direction:column">
            <div style="display:flex;">
              <span class="update-user-button"><i class='icon-floppy'></i>{'Update'|translate}</span>
              <span class="close-update-button">{'Close'|translate}</span>
              <span class="update-user-success icon-green">{'User updated'|translate}</span>
              <span class="update-user-fail  icon-cancel"></span>
            </div>
            <div>
            </div>
          </div>
        </div>
      </div>
      <div class="preferences-container">
        <div class="user-property-column-title">
          <p>{'Preferences'|translate}</p>
        </div>
        <div class="user-property-label photos-select-bar">{'Photos per page'|translate}
          <span class="nb-img-page-infos"></span>
          <div class="slider-bar-wrapper">
            <div class="slider-bar-container"></div>
          </div>
          <input name="recent_period" />
        </div>
        <div class="user-property-theme">
          <p class="user-property-label">{'Theme'|translate}</p>
          <div class="user-property-select-container">
            <select name="privacy" class="user-property-select">
              {html_options options=$theme_options selected=$theme_selected}
            </select>
          </div>
        </div>
        <div class="user-property-lang">
          <p class="user-property-label">{'Language'|translate}</p>
          <div class="user-property-select-container">
            <select name="privacy" class="user-property-select">
              {html_options options=$language_options selected=$language_selected}
            </select>
          </div>
        </div>
        <div class="user-property-label period-select-bar">{'Recent period'|translate}
          <span class="recent_period_infos">
            <!-- 7 days -->
          </span>
          <div class="slider-bar-wrapper">
            <div class="slider-bar-container"></div>
          </div>
        </div>

        <div class="user-list-checkbox" name="expand_all_albums">
          <span class="select-checkbox">
            <i class="icon-ok"></i>
          </span>
          <span class="user-list-checkbox-label">{'Expand all albums'|translate}</span>
        </div>
        <div class="user-list-checkbox" name="show_nb_comments">
          <span class="select-checkbox">
            <i class="icon-ok"></i>
          </span>
          <span class="user-list-checkbox-label">{'Show number of comments'|translate}</span>
        </div>
        <div class="user-list-checkbox" name="show_nb_hits">
          <span class="select-checkbox">
            <i class="icon-ok"></i>
          </span>
          <span class="user-list-checkbox-label">{'Show number of hits'|translate}</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="AddUser" class="UserListPopIn">
  <div class="AddUserPopInContainer">
    <a class="icon-cancel CloseUserList CloseAddUser"></a>

    <div class="AddIconContainer">
      <span class="AddIcon icon-blue icon-plus-circled"></span>
    </div>
    <div class="AddIconTitle">
      <span>{'Add a new user'|translate}</span>
    </div>
    <div class="AddUserInputContainer">
      <label class="user-property-label AddUserLabelUsername">{'Username'|translate}
        <input class="user-property-input" autocomplete="off" />
      </label>
    </div>

    <div class="AddUserInputContainer">
      <div class="AddUserPasswordWrapper">
        <label for="AddUserPassword" class="user-property-label AddUserLabelPassword">{'Password'|translate}</label>
        <span id="show_password" class="icon-eye"></span>
      </div>
      <input id="AddUserPassword" class="user-property-input" type="password" autocomplete="new-password" />

      <div class="AddUserGenPassword">
        <span class="icon-dice-solid"></span><span>{'Generate random password'|translate}</span>
      </div>
    </div>

    <div class="AddUserInputContainer">
      <label class="user-property-label AddUserLabelEmail">{'Email'|translate}
        <input class="user-property-input" autocomplete="off" />
      </label>
    </div>

    <div class="user-list-checkbox" name="send_by_email">
      <span class="select-checkbox">
        <i class="icon-ok"></i>
      </span>
      <span class="user-list-checkbox-label">{'Send connection settings by email'|translate}</span>
    </div>

    <div class="AddUserErrors  icon-cancel">
    </div>

    <div class="AddUserSubmit">
      <span class="icon-plus"></span><span>{'Add User'|translate}</span>
    </div>

    <div class="AddUserCancel" style="display:none;">
      <span>{'Cancel'|translate}</span>
    </div>
  </div>
</div>

