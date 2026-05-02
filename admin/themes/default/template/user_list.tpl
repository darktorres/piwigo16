
{combine_script id='common' load='header' path='admin/themes/default/js/common.js'}

{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}

<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

{combine_script id='user_list' load='footer' path='admin/themes/default/js/user_list.js'}

<div class="selection-mode-group-manager" style="right:30px">
  <label class="switch">
    <input type="checkbox" id="toggleSelectionMode">
    <span class="slider round"></span>
  </label>
  <p>{'Selection mode'|@translate}</p>
</div>


<div id="user-table">
  <div id="user-table-content">
    <div class="user-manager-header">

      <div class="UserViewSelector">
        <input type="radio" name="layout" class="switchLayout" id="displayCompact" {if $view_selector == 'compact'}checked{/if}/><label for="displayCompact"><span class="icon-th-large firstIcon tiptip" title="{'Compact View'|translate}"></span></label><input type="radio" name="layout" class="switchLayout tiptip" id="displayLine" {if $view_selector == 'line'}checked{/if}/><label for="displayLine"><span class="icon-th-list tiptip" title="{'Line View'|translate}"></span></label><input type="radio" name="layout" class="switchLayout" id="displayTile" {if $view_selector == 'tile'}checked{/if}/><label for="displayTile"><span class="icon-pause lastIcon tiptip" title="{'Tile View'|translate}"></span></label>
      </div>

      <div style="display:flex;justify-content:space-between; flex-grow:1;">
        <div style="display:flex; align-items: center;">
          <div class="not-in-selection-mode user-header-button add-user-button" style="margin: auto;">
            <label class="head-button-2 icon-plus-circled">
              <p>{'Add a user'|@translate}</p>
            </label>
          </div>

          <div class="not-in-selection-mode user-header-button" style="margin: auto;">
            <label class="head-button-2 icon-user-secret edit-guest-user-button">
              <p>{'Edit guest user'|@translate}</p>
            </label>
          </div>
          <div id="AddUserSuccess">
            <label class="icon-ok">
              <span>{'New user added'|@translate}</span><span class="icon-pencil edit-now">{'Edit'|@translate}</span>
            </label>
          </div>
          <div class="in-selection-mode">
            <div id="checkActions">
              <span>{'Select'|@translate}</span>
              <a href="#" id="selectAllPage">{'The whole page'|@translate}</a>
              <a href="#" id="selectSet">{'The whole set'|@translate}</a><span class="loading" style="display:none"><img src="themes/default/images/ajax-loader-small.gif"></span>
              <a href="#" id="selectNone">{'None'|@translate}</a>
              <a href="#" id="selectInvert">{'Invert'|@translate}</a>
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
      <span>{'Filters'|@translate}</span>
      <span class="filter-counter"></span>
    </div>
    <div id='search-user'>
        <div class='search-info'> </div>
          {*This input (#user_search2) is used to bait the chrome autocomplete tool. It is hidden in navigator and is not meant to be seen.*}
          <input id="user_search2" class='search-input2' type='text' placeholder='{'Search'|@translate}'> 
          <span class='icon-search search-icon'> </span>
          <span class="icon-cancel search-cancel"{if isset($search_input)} style="display:inline"{/if}></span>
          <input autocomplete="one-time-code" id="user_search" class='search-input' type='text' placeholder='{'Search'|@translate}'{if isset($search_input)} value="{$search_input}"{/if}>
        </div>
    <div class="advanced-filter">
      <div class="advanced-filter-header">
        <span class="advanced-filter-title">{'Advanced filters'|@translate}</span>
        <span class="advanced-filter-close icon-cancel"></span>
      </div>
      <div class="advanced-filter-container">
      <div class="advanced-filter-status advanced-filter-item">
          <label class="advanced-filter-item-label">{'Status'|@translate}</label>
          <div class="advanced-filter-select-container advanced-filter-item-container">
            <select class="user-action-select advanced-filter-select" name="filter_status">
              <option value="" label="" selected></option>
              {foreach from=$nb_users_by_status key=status_value item=status}
                {if isset($status.name) and isset($status.counter)}
                  <option value="{$status_value}">{$status.name} ({$status.counter})</option>
                {else}
                  <option value="{$status_value}" disabled>{$status}</option>
                {/if}
              {/foreach}
            </select>
          </div>
        </div>
        <div class="advanced-filter-level advanced-filter-item">
          <label class="advanced-filter-item-label">{'Privacy level'|@translate}</label>
          <div class="advanced-filter-select-container advanced-filter-item-container">
            <select class="user-action-select advanced-filter-select" name="filter_level" size="1">
              <option value="" label="" selected></option>
              {foreach from=$nb_users_by_level key=level_value item=level}
                {if isset($level.name) and isset($level.counter)}
                  <option value="{$level_value}">{$level.name} ({$level.counter})</option>
                {else}
                  <option value="{$level_value}" disabled>{$level}</option>
                {/if}
              {/foreach}
            </select>
          </div>
        </div>
        <div class="advanced-filter-group advanced-filter-item">
          <label class="advanced-filter-item-label">{'Group'|@translate}</label>
          <div class="advanced-filter-select-container advanced-filter-item-container">
            <select class="user-action-select advanced-filter-select" name="filter_group">
              <option value="" label="" selected></option>
              {foreach from=$groups_for_filter item=group}
                <option value="{$group.id}" {if 0 == $group.counter}disabled{/if}>
                  {$group.name}{if $group.counter > 0} ({$group.counter}){/if}
                </option>
              {/foreach}
            </select>
          </div>
        </div>
        <div class="advanced-filter-date advanced-filter-item">
          <div class="advanced-filter-date-title" style="display:flex">
            <span class="advanced-filter-item-label">{'Registered'|@translate}</span>
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
        <span id="usr-list-user">{'Username'|@translate} <span id="icon-usr-list-user" class="icon-up" style="display: none;"></span></span>
      </div>
      <!-- status -->
      <div class="user-header-col user-header-status">
        <span>{'Status'|@translate}</span>
      </div>
      <!-- email adress -->
      <div class="user-header-col user-header-email not-in-selection-mode">
        <span>{'Email Adress'|@translate}</span>
      </div>
      {* <!-- groups -->
      <div class="user-header-col user-header-groups">
        <span>{'Groups'|@translate}</span>
      </div> *}
      <!-- registration date -->
      <div class="user-header-col user-header-registration">
        <span id="usr-list-registered">{'Registered'|@translate} <span id="icon-usr-list-registered" class="icon-up"></span></span>
      </div>
       <!-- groups -->
       <div class="user-header-col user-header-groups">
       <span>{'Groups'|@translate}</span>
     </div>
    </div>
    <div class="user-update-spinner icon-spin6 animate-spin"></div>
    <div class="user-container-wrapper">
    </div>
    <!-- Pagination -->
    <div class="user-pagination">
      <div class="pagination-per-page">
        <span class="thumbnailsActionsShow" style="font-weight: bold;">{'Display'|@translate}</span>
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
        <div class="pagination-arrow rigth">
          <span class="icon-left-open"></span>
        </div>
      </div>
    </div>
  </div>
  <div id="selection-mode-block" class="in-selection-mode tag-selection" style="width: 250px; min-width:250px;display: block;position:relative">
    <div class="user-selection-content">
      <div class="selection-mode-ul">
        <p>{'Your selection'|@translate}</p>
        <div class="user-selected-list">
        </div>
        <div class="selection-other-users"></div>
      </div>
      <fieldset id="action">
        <legend>{'Action'|@translate}</legend>

        <div id="forbidAction">{'No users selected, no actions possible.'|@translate}</div>
        <div id="permitActionUserList" style="display:block">

          <div class="user-action-select-container">
            <select class="user-action-select" name="selectAction">
              <option value="-1">{'Choose an action'|@translate}</option>
              <optgroup label="Actions">
                <option value="delete" class="icon-trash">{'Delete selected users'|@translate}</option>
                <option value="status">{'Status'|@translate}</option>
                <option value="group_associate">{'associate to group'|translate}</option>
                <option value="group_dissociate">{'dissociate from group'|@translate}</option>
                <option value="enabled_high">{'High definition enabled'|@translate}</option>
                <option value="level">{'Privacy level'|@translate}</option>
                <option value="nb_image_page">{'Number of photos per page'|@translate}</option>
                <option value="theme">{'Theme'|@translate}</option>
                <option value="language">{'Language'|@translate}</option>
                <option value="recent_period">{'Recent period'|@translate}</option>
                <option value="expand">{'Expand all albums'|@translate}</option>
                {if $ACTIVATE_COMMENTS}
                <option value="show_nb_comments">{'Show number of comments'|@translate}</option>
                {/if}
                <option value="show_nb_hits">{'Show number of hits'|@translate}</option>
              </optgroup>
            </select>
          </div>
          {* delete *}
          <div id="action_delete" class="bulkAction">
            <div class="user-list-checkbox" name="confirm_deletion">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'Are you sure?'|@translate}</span>
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
              <span class="user-list-checkbox-label">{'Yes'|@translate}</span>
            </span>
            <span class="user-list-checkbox" data-selected="1" name="enabled_high_no">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'No'|@translate}</span>
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
              <br/>
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
              <span class="user-list-checkbox-label">{'Yes'|@translate}</span>
            </span>
            <span class="user-list-checkbox" data-selected="1" name="expand_no">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'No'|@translate}</span>
            </span>
          </div>

          {* show_nb_comments *}
          <div id="action_show_nb_comments" class="bulkAction yes_no_radio">
            <span class="user-list-checkbox" name="show_nb_comments_yes">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'Yes'|@translate}</span>
            </span>
            <span class="user-list-checkbox" data-selected="1" name="show_nb_comments_no">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'No'|@translate}</span>
            </span>
          </div>

          {* show_nb_hits *}
          <div id="action_show_nb_hits" class="bulkAction yes_no_radio">
            <span class="user-list-checkbox" name="show_nb_hits_yes">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'Yes'|@translate}</span>
            </span>
            <span class="user-list-checkbox" data-selected="1" name="show_nb_hits_no">
              <span class="select-checkbox">
                <i class="icon-ok"></i>
              </span>
              <span class="user-list-checkbox-label">{'No'|@translate}</span>
            </span>
          </div>

          <p id="applyActionBlock" style="display:none" class="actionButtons">
            <input id="applyAction" class="submit" type="submit" value="{'Apply action'|@translate}" name="submit"> <span id="applyOnDetails"></span></input>
            <span id="applyActionLoading" style="display:none"><img src="themes/default/images/ajax-loader-small.gif"></span>
            <br />
            <span class="infos icon-ok icon-green" style="display:inline-block;display:none;max-width:100%;margin:0;margin-top:30px;min-height:0;">{'Users modified'|translate}</span>
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
        <span><!-- initials --></span>
      </div>
    </div>
    <div class="user-col user-container-username">
      <span><!-- name --></span>
    </div>
    <div class="user-col user-container-status">
      <span><!-- status --></span>
    </div>
    <div class="user-col user-container-email not-in-selection-mode">
      <span><!-- email --></span>
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
          <span class="user-container-registration-date-since"><!-- date_since --></span>
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
          <div class="edit-user-icons">
            <span class="icon-king tiptip" id="who_is_the_king" title="plg is the king ?"></span>
            <span class="delete-user-button icon-trash-1"></span>
          </div>
          <div class="user-property-initials">
            <div>
              <span class="icon-blue"><!-- Initials (JP) --></span>
            </div>
          </div>
          <div class="user-property-username">
            <span class="edit-username-title"><!-- Name (Jessy Pinkman) --></span>
            <span class="edit-username-specifier"><!-- You specifire (you) --></span>
            <span class="edit-username icon-pencil"></span>
          </div>
          <div class="user-property-password-container">
            <div class="user-property-password edit-password">
              <p class="user-property-button button-edit-password-icon head-button-2"><span class="icon-key user-edit-icon"> </span>{'Password'|@translate}</p>
            </div>
            <div class="user-property-permissions">
              <a href="#" ><p class="user-property-button head-button-2"> <span class="icon-lock user-edit-icon"> </span>{'Permissions'|@translate}</p></a>
            </div>
            <div class="user-stats">
              <div class="user-property-history">
                <a href="" ><p class="user-property-button head-button-2"> <span class="icon-signal user-edit-icon"> </span>{'Visit history'|@translate}</p></a>
              </div>
            </div>
          </div>
          <div class="user-property-register-visit">
            <div>
              <span class="edit-user-register-label">{'Registered'|@translate}</span>
              <span class="user-property-register"><!-- Registered date XX/XX/XXXX --></span>
            </div>
            <div>
              <span class="edit-user-lastvisit-label">{'Last visit'|@translate}</span>
              <span class="user-property-last-visit"><!-- Last Visit date XX/XX/XXXX --></span>
            </div>
          </div>
        </div>
        <div class="edit-user-tab">
          <div class="edit-user-tab-title">
            <p class="edit-user-tabsheet selected tiptip" id="name_tab_properties" title="{'Properties'|@translate}">{'Properties'|@translate}</p>
            <p class="edit-user-tabsheet tiptip" id="name_tab_preferences" title="{'Preferences'|@translate}">{'Preferences'|@translate}</p>
            {* <p class="edit-user-tabsheet tiptip" id="name_tab_notifications" title="{'Notifications'|@translate}">{'Notifications'|@translate}</p> *}
          </div>
          <div class="edit-user-slides">
            <!-- Pop in tabs 1 Properties -->
            <div class="properties-container" id="tab_properties">
              <div class="user-property-email">
                <p class="user-property-label">{'Email Adress'|@translate}</p>
                <input type="text" class="user-property-input" value="contact@jessy-pinkman.com" disabled="false" />
              </div>
              <div class="user-property-status">
                <p class="user-property-label">{'Status'|@translate}
                  <span class="icon-help-circled" title="<div class='tooltip-status-content'>
                    <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_webmaster'|translate}</span><span class='tooltip-col2'>{'Has access to all administration functionnalities. Can manage both configuration and content.'|translate}</span></div>
                    <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_admin'|translate}</span><span class='tooltip-col2'>{'Has access to administration. Can only manage content: photos/albums/users/tags/groups.'|translate}</span></div>
                    <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_normal'|translate}</span><span class='tooltip-col2'>{'No access to administration, can see private content with appropriate permissions.'|translate}</span></div>
                    <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_generic'|translate}</span><span class='tooltip-col2'>{'Can be shared by several individuals without conflict (they cannot change the password).'|translate}</span></div>
                    <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_guest'|translate}</span><span class='tooltip-col2'>{'Equivalent to deactivation. The user is still in the list, but can no longer log in.'|translate}</span></div>
                  </div">
                  </span>
                </p>
                <div class="user-property-select-container">
                  <select name="status" class="user-property-select">
                    <option value="webmaster">{'user_status_webmaster'|@translate}</option>
                    <option value="admin">{'user_status_admin'|@translate}</option>
                    <option value="normal">{'user_status_normal'|@translate}</option>
                    <option value="generic">{'user_status_generic'|@translate}</option>
                    <option value="guest">{'user_status_guest'|@translate} ({'Deactivated'|@translate})</option>
                  </select>
                </div>
              </div>
              <div class="user-property-level">
                <p class="user-property-label">{'Privacy level'|@translate}</p>
                <div class="user-property-select-container">
                  <select name="privacy" class="user-property-select">
                    <option value="0">{'Level 0'|@translate}</option>
                    <option value="1">{'Level 1'|@translate}</option>
                    <option value="2">{'Level 2'|@translate}</option>
                    <option value="4">{'Level 4'|@translate}</option>
                    <option value="8">{'Level 8'|@translate}</option>
                  </select>
                </div>
              </div>
              <div class="user-property-group-container">
                <p class="user-property-label">{'Groups'|@translate}</p>
                <div class="user-property-select-container user-property-group">
                  <select class="user-property-select" data-selectize="groups"
                    placeholder="{'Select groups or type them'|translate}" name="group_id[]" multiple
                    style="box-sizing:border-box;"></select>
                  <p class="user-property-group-text">
                    {* {'Some of these groups give access to notifications. To find out more, go to the Notifications tab.'|@translate} *}
                  </p>
                </div>
              </div>

              <div class="user-list-checkbox" name="hd_enabled" style="margin-bottom: 35px;">
                <span class="select-checkbox">
                  <i class="icon-ok"></i>
                </span>
                <span class="user-list-checkbox-label">{'High definition enabled'|translate}</span>
              </div>
            </div>
            <!-- Pop in tabs 2 Preferences -->
            <div class="preferencies-container" id="tab_preferences">
              <div class="user-property-label photos-select-bar">{'Photos per page'|translate}
                <span class="nb-img-page-infos"></span>
                <div class="slider-bar-wrapper">
                  <div class="slider-bar-container"></div>
                </div>
                <input name="recent_period" />
              </div>
              <div class="user-property-theme">
                <p class="user-property-label">{'Theme'|@translate}</p>
                <div class="user-property-select-container">
                  <select name="privacy" class="user-property-select">
                    {html_options options=$theme_options selected=$theme_selected}
                  </select>
                </div>
              </div>
              <div class="user-property-lang">
                <p class="user-property-label">{'Language'|@translate}</p>
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

              <div class="user-group-checkbox">
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
             <!-- Pop in tabs 3 Notifications WIP-->
            {* <div class="notifications-container" id="tab_notifications">
              <p style="margin: 0;">Notifications tab WIP</p>
            </div> *}
          </div>
        </div>
      </div>
      <div class="update-container">
        <span class="close-update-button icon-cancel-circled">{'Close'|@translate}</span>
        <p>
          <span class="update-user-success icon-green icon-ok">{'User updated'|@translate}</span>
          <span class="update-user-fail icon-cancel"></span>
          <span class="update-user-button"><i class='icon-floppy'></i>{'Update'|@translate}</span>
        </p>
      </div>
    </div>
  </div>

  {* Modal edit username in pop in User *}
  <div class="user-property-username-change">
    <div class="user-property-username-change-content">
      
      <div class="user-property-username-change-input">
        <div class="summary-input-container">
          <input class="user-property-input user-property-input-username" value=""
            placeholder="{'Username'|@translate}" />
        </div>
        <div class="group-button">
          <span class="edit-username-cancel">{'Cancel'|@translate}</span>
          <span class="icon-floppy edit-username-validate">{'Validate'|@translate}</span>
        </div>
      </div>

      <div class="edit-username-success" style="display: none;">
        <div class="update-username-success icon-green">
          <span class="icon-ok"></span>
          <span>{'Username successfully modified'|@translate|escape}</span>
        </div>
        <p class="edit-username-success-ok"><span class="icon-button icon-ok" id="close_username_success">{'Ok'|@translate}</span></p>
      </div>

    </div>
  </div>
  {* Modal edit password in pop in User *}
  <div class="user-property-password-change">
    <div class="user-property-password-change-content">

      <div class="user-property-password-choice">
        <div class="password-choice-content">
          <p class="head-button-2" id="copy_password_link"><span class="icon-link-1"></span> {'Copy the password link'|@translate|escape}</p>
          <p class="head-button-2" id="send_password_link"><span class="icon-mail-alt"></span> {'Resend password link'|@translate|escape}</p>
          <p class="head-button-2" id="edit_modal_password"><span class="icon-pencil"></span> {'Change password'|@translate|escape}</p>
        </div>
        <p class="edit-password-cancel">{'Cancel'|@translate}</p>
      </div>

      <div class="user-property-password-change-inputs" style="display: none;">
        <form data-prevent-submit>
          <input type="text" style="display: none;" autocomplete="username" />
          <div class="summary-input-container">
            <div class="user-property-input-icon" style="margin-bottom: 10px;">
              <input class="user-property-input user-property-input-password" value=""
                placeholder="{'New password'|@translate}" type="password" id="edit_user_password" autocomplete="new-password" />
              <span class="icon-eye icon-show-password"></span>
            </div>
            <div class="user-property-input-icon">
              <input class="user-property-input user-property-input-password-conf" value=""
                placeholder="{'Confirm Password'|@translate}" type="password" id="edit_user_conf_password" autocomplete="new-password" />
              <span class="icon-eye icon-show-password"></span>
            </div>
          </div>
        </form>
        <div class="EditUserGenPassword">
          <span class="icon-dice-solid"></span><span>{'Generate random password'|@translate}</span>
        </div>
        <div class="EditUserErrors icon-cancel">
        </div>
        <div class="group-button">
          <span class="edit-password-cancel">{'Cancel'|@translate}</span>
          <span class="icon-floppy edit-password-validate">{'Validate'|@translate}</span>
        </div>
      </div>

      <div class="edit-password-success" id="edit_password_success_change" style="display: none;">
        <div class="update-password-success icon-green">
          <span class="icon-ok" id="password_msg_success">{'Password updated'|@translate}</span>
        </div>
        <p class="user-property-button head-button-2" id="copy_password"><span class="icon-docs button-copy-pass">{'Copy password'|@translate}</span></p>
        <p class="edit-password-success-ok"><span class="icon-button icon-ok" id="close_password_success">{'Ok'|@translate}</span></p>
      </div>

      <div class="edit-password-success" id="edit_password_result_mail" style="display: none;">
        <div class="update-password-success icon-green" id="result_send_mail">
          <span class="icon-ok" id="icon_password_msg_result_mail"></span>
          <span id="password_msg_result_mail">text</span>
        </div>
        <p class="edit-password-success-ok"><span class="icon-button icon-ok" id="close_password_mail_close">{'Ok'|@translate}</span></p>
      </div>

      <div class="edit-password-success" id="edit_password_result_mail_copy" style="display: none;">
        <div class="edit-password-success-reset-link">
          <input class="edit-password-success-input" id="result_send_mail_copy_input" />
          <span class="icon-docs" id="result_send_mail_copy_btn"></span>
        </div>
        <div class="update-password-success icon-green" id="result_send_mail_copy">
          <span class="icon-ok" id="result_send_mail_copy_icon"></span>
          <span id="result_send_mail_copy_msg">{'Copied link'|@translate}</span>
        </div>
        <p class="edit-password-success-ok"><span class="icon-button icon-ok" id="close_password_mail_send_close">{'Ok'|@translate}</span></p>
      </div>
      
    </div>
  </div>
  {* Modal edit main user in pop in User *}
  <div class="user-property-main-user-change">
    <div class="user-property-main-user-content">
      <div class="user-property-main-user-content-header">
        <span class="icon-king main-user-icon"></span>
        <span class="main-user-title">{'Changing the main user'|@translate|escape}</span>
      </div>

      <div class="user-property-main-user-body">
        <div class="main-user-proceed">
          <span class="main-user-proceed-desc">{'You are about to set %s as main user instead of %s, do you wish to continue?'|@translate}</span>
          <div class="main-user-proceed-footer">
            <span class="user-property-main-user-cancel">{'Cancel'|@translate}</span>
            <span class="head-button-2 main-user-btn-proceed"><span class="icon-right">{'Yes, let\'s proceed'|@translate|escape}</span></span>
          </div>
        </div>

        <div class="main-user-rewrite" style="display: none;">
          <span class="main-user-rewrite-desc">{'To be sure, please rewrite the word “%s” below'|@translate|escape} :</span>
          <div class="main-user-rewrite-footer">
            <input type="text" id="main_user_rewrite" />
            <span id="main_user_rewrite_icon"></span>
          </div>
        </div>

        <div class="main-user-validate" style="display: none;">
          <span class="main-user-validate-desc">{'You can now change the main user from %s to %s.'|@translate|escape}</span>
          <div class="main-user-validate-footer">
            <span class="user-property-main-user-cancel">{'Cancel'|@translate}</span>
            <span class="main-user-btn-validate"><span class="icon-floppy"></span> {'Validate'|@translate}</span>
          </div>
        </div>

        <div class="main-user-success" style="display: none;">
          <div class="update-main-user-success icon-green">
            <span class="icon-ok"></span>
            <span class="main-user-success-desc">{'%s is the new main user'|@translate|escape}</span>
          </div>
          <span class="user-property-main-user-close"><span class="icon-button icon-ok" id="main_user_success_close">{'Ok'|@translate}</span></span>
        </div>
      </div>

    </div>
  </div>
</div>

<div id="GuestUserList" class="UserListPopIn">

  <div class="GuestUserListPopInContainer">

    <a class="icon-cancel CloseUserList CloseGuestUserList"></a>
    <div id="guest-msg" class="messages">
      <span class="eiw-icon icon-info-circled-1"></span>
      <span>{'Users not logged in will have these settings applied, these settings are used by default for new users'|@translate}</span>
    </div>
    <div class="summary-properties-update-container">
      <div class="summary-properties-container">
        <div class="summary-container">
          <div class="user-property-initials">
            <div>
              <span class="icon-blue"><i class="icon-user-secret"> </i></span>
            </div>
          </div>
          <div class="user-property-username">
            <span class="edit-username-title"><!-- name -> Jessy Pinkman --></span>
            <span class="edit-username-specifier"><!-- you specifier(you) --></span>
          </div>
          <div class="user-property-username-change">
            <div class="summary-input-container">
              <input class="user-property-input user-property-input-username" value="" placeholder="{'Username'|@translate}" />
            </div>
            <span class="icon-ok edit-username-validate"></span>
            <span class="icon-cancel-circled edit-username-cancel"></span>
          </div>
          <div class="user-property-password-container">
            <div class="user-property-password edit-password">
              <p class="user-property-button head-button-2 unavailable"><span class="icon-key user-edit-icon"></span>{'Change Password'|@translate}</p>
            </div>
            <div class="user-property-password-change">
              <div class="summary-input-container">
              <input class="user-property-input user-property-input-password" value="" placeholder="{'Password'|@translate}" />
              </div>
              <span class="icon-ok edit-password-validate"></span>
              <span class="icon-cancel-circled edit-password-cancel"></span>
            </div>
            <div class="user-property-permissions">
              <a href="admin.php?page=user_perm&user_id={$guest_id}"><p class="user-property-button head-button-2"><span class="icon-lock user-edit-icon"></span>{'Permissions'|@translate}</p></a>
            </div>
          </div>
        </div>
        
        <div class="guest-edit-user-tab">
          <div class="guest-edit-user-tab-title">
            <p class="guest-edit-user-tabsheet selected tiptip" id="name_guest_tab_properties" title="{'Properties'|@translate}">{'Properties'|@translate}</p>
            <p class="guest-edit-user-tabsheet tiptip" id="name_guest_tab_preferences" title="{'Preferences'|@translate}">{'Preferences'|@translate}</p>
          </div>

          <div class="guest-edit-user-slides">
            <div class="properties-container" id="guest_tab_properties">
              <div class="user-property-email">
                <p class="user-property-label">{'Email Adress'|@translate}</p>
                <input type="text" class="user-property-input" value="N/A" readonly />
              </div>
              <div class="user-property-status">
                <p class="user-property-label">{'Status'|@translate}</p>
                <div class="user-property-select-container notClickableBefore">
                  <select name="status" class="user-property-select notClickable">
                    <option value="guest">{'Guest'|@translate}</option>
                  </select>
                </div>
              </div>
              <div class="user-property-level">
                <p class="user-property-label">{'Privacy Level'|@translate}</p>
                <div class="user-property-select-container">
                  <select name="privacy" class="user-property-select">
                    <option value="0">{'Level 0'|@translate}</option>
                    <option value="1">{'Level 1'|@translate}</option>
                    <option value="2">{'Level 2'|@translate}</option>
                    <option value="4">{'Level 4'|@translate}</option>
                    <option value="8">{'Level 8'|@translate}</option>
                  </select>
                </div>
              </div>
              <div class="user-property-group-container">
                <p class="user-property-label">{'Groups'|@translate}</p>
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

            <div class="preferences-container" id="guest_tab_preferences">
              <div class="user-property-label photos-select-bar">{'Photos per page'|translate}
                <span class="nb-img-page-infos"></span>
                <div class="slider-bar-wrapper">
                  <div class="slider-bar-container"></div>
                </div>
                <input name="recent_period" />
              </div>
              <div class="user-property-theme">
                <p class="user-property-label">{'Theme'|@translate}</p>
                <div class="user-property-select-container">
                  <select name="privacy" class="user-property-select">
                    {html_options options=$theme_options selected=$theme_selected}
                  </select>
                </div>
              </div>
              <div class="user-property-lang">
                <p class="user-property-label">{'Language'|@translate}</p>
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

      <div class="update-container">
        <span class="close-update-button icon-cancel-circled">{'Close'|@translate}</span>
        <p>
          <span class="update-user-success icon-green">{'User updated'|@translate}</span>
          <span class="update-user-fail  icon-cancel"></span>
          <span class="update-user-button"><i class='icon-floppy'></i>{'Update'|@translate}</span>
        </p>
      </div>

    </div>
  </div>
</div>

<div id="AddUser" class="UserListPopIn">
  <div class="AddUserPopInContainer">
    <a class="icon-cancel CloseUserList CloseAddUser"></a>
    <div class="AddUserScrollableContent">
    <div class="AddIconContainer">
      <span class="AddIcon icon-blue icon-plus-circled"></span>
    </div>
    <div class="AddIconTitle">
      <span>{'Add a new user'|@translate}</span>
    </div>

    <div id="AddUserFieldContainer">
      <div class="AddUserInputContainer">
        <label class="user-property-label AddUserLabelUsername">{'Username'|@translate}
          <input class="user-property-input" />
        </label>
      </div>

      <div class="AddUserInputContainer">
        <label class="user-property-label AddUserLabelEmail">{'Email'|@translate}
          <input class="user-property-input" />
        </label>
      </div>

      <div class="AddUserInputContainer">
        <div class="user-property-status">
          <label class="user-property-label">{'Status'|@translate}
            <span class="icon-help-circled" title="
            <div class='tooltip-status-content'>
              <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_webmaster'|translate}</span><span class='tooltip-col2'>{'Has access to all administration functionnalities. Can manage both configuration and content.'|translate}</span></div>
              <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_admin'|translate}</span><span class='tooltip-col2'>{'Has access to administration. Can only manage content: photos/albums/users/tags/groups.'|translate}</span></div>
              <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_normal'|translate}</span><span class='tooltip-col2'>{'No access to administration, can see private content with appropriate permissions.'|translate}</span></div>
              <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_generic'|translate}</span><span class='tooltip-col2'>{'Can be shared by several individuals without conflict (they cannot change the password).'|translate}</span></div>
              <div class='tooltip-status-row'><span class='tooltip-col1'>{'user_status_guest'|translate}</span><span class='tooltip-col2'>{'Equivalent to deactivation. The user is still in the list, but can no longer log in.'|translate}</span></div>
            </div">
            </span>
          </label>
          <div class="user-property-select-container">
            <select name="status" class="user-property-select">
              <option value="webmaster">{'user_status_webmaster'|@translate}</option>
              <option value="admin">{'user_status_admin'|@translate}</option>
              <option value="normal">{'user_status_normal'|@translate}</option>
              <option value="generic">{'user_status_generic'|@translate}</option>
            </select>
          </div>
        </div>
      </div>

      <div id="add_user_password" style="display: none;">
        <form data-prevent-submit>
          <input type="text" style="display: none;" autocomplete="username" />
          <div class="AddUserGenPassword">
            <label for="add_user_pass" class="user-property-label AddUserLabelPassword">{'Password'|@translate}</label>
            <span class="icon-dice-solid"> {'Generate random password'|@translate}</span>
          </div>
          <div class="user-property-input-icon" style="margin-bottom: 5px;">
            <input id="add_user_pass" class="user-property-input user-property-input-password" value=""
              placeholder="{'Password'|@translate}" type="password" autocomplete="new-password" />
            <span class="icon-eye icon-show-password"></span>
          </div>

          <label for="add_user_confpass" class="user-property-label AddUserLabelPasswordConf">{'Confirm Password'|@translate}</label>
          <div class="user-property-input-icon" style="margin-bottom: 5px;">
            <input id="add_user_confpass" class="user-property-input user-property-input-password-conf" value=""
              placeholder="{'Confirm Password'|@translate}" type="password" autocomplete="new-password" />
            <span class="icon-eye icon-show-password"></span>
          </div>
        </form>
      </div>

      <div class="AddUserInputContainer">
        <div class="user-property-level">
          <p class="user-property-label">{'Privacy level'|@translate}</p>
          <div class="user-property-select-container">
            <select name="privacy" class="user-property-select">
              <option value="0">{'Level 0'|@translate}</option>
              <option value="1">{'Level 1'|@translate}</option>
              <option value="2">{'Level 2'|@translate}</option>
              <option value="4">{'Level 4'|@translate}</option>
              <option value="8">{'Level 8'|@translate}</option>
            </select>
          </div>
        </div>
      </div>

      <div class="AddUserInputContainer">
        <div class="user-property-group-container">
          <p class="user-property-label">{'Groups'|@translate}</p>
          <div class="user-property-select-container user-property-group">
            <select class="user-property-select" data-selectize="groups"
              placeholder="{'Select groups or type them'|translate}" name="group_id[]" multiple
              style="box-sizing:border-box;"></select>
          </div>
        </div>
      </div>

      <div class="AddUserInputContainer">
        <div class="user-list-checkbox" name="hd_enabled">
          <span class="select-checkbox">
            <i class="icon-ok"></i>
          </span>
          <span class="user-list-checkbox-label">{'High definition enabled'|translate}</span>
        </div>
      </div>

      <div class="AddUserErrors  icon-cancel">
      </div>

      <div class="AddUserSubmitContainer">
        <div class="AddUserCancel">
          <span>{'Cancel'|@translate}</span>
        </div>

        <div class="AddUserSubmit">
          <span class="icon-plus"></span><span>{'Add User'|@translate}</span>
        </div>
      </div>
    </div>

    <div id="AddUserSuccessContainer" style="display: none;">
      <p class="icon-green border-green icon-ok AddUserResult" id="AddUserUpdated"> <span id="AddUserUpdatedText">{'User updated'|@translate}</span></p>
      <p class="AddUserTextField" id="AddUserTextField"></p>
      <div class="AddUserPasswordInputContainer" id="AddUserPasswordInputContainer">
        <input class="AddUserPasswordInput" id="AddUserPasswordLink" />
        <span class="icon-docs" id="AddUserCopyPassword"></span>
      </div>
      <p class="icon-button" id="AddUserButton"><span class="icon-ok"></span> {'Ok'|@translate}</p>
    </div>
    </div>

  </div>
</div>

{combine_css path="admin/themes/default/css/pages/user-list.css"}
