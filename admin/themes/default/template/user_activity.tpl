{combine_css path='node_modules/tom-select/dist/css/tom-select.default.css'}
{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}

{if $vite_user_activity}
<script type="module" src="admin/themes/default/js/dist/{$vite_user_activity}"></script>
{/if}

{footer_script}<script>
  window.usersServerKey = '{$CACHE_KEYS.users}';
  window.usersServerId = '{$CACHE_KEYS._hash}';
  window.rootUrl = '{$ROOT_URL}';
  window.users_key = "{"Users"|translate}";
  window.nbUsers = {$nb_users};

  window.actionType_add = "{'add'|translate}";
  window.actionType_delete = "{'deletion'|translate}";
  window.actionType_move = "{'move'|translate}";
  window.actionType_edit = "{'edit'|translate}";
  window.actionType_login = "{'login'|translate}";
  window.actionType_logout = "{'logout'|translate}";

  window.actionInfos_album_added = "{'%d album added'|translate}";
  window.actionInfos_album_deleted = "{'%d album deleted'|translate}";
  window.actionInfos_album_edited = "{'%d album edited'|translate}";
  window.actionInfos_album_moved = "{'%d album moved'|translate}";
  window.actionInfos_albums_added = "{'%d albums added'|translate}";
  window.actionInfos_albums_deleted = "{'%d albums deleted'|translate}";
  window.actionInfos_albums_edited = "{'%d albums edited'|translate}";
  window.actionInfos_albums_moved = "{'%d albums moved'|translate}";

  window.actionInfos_user_added = "{'%d user added'|translate}";
  window.actionInfos_user_deleted = "{'%d user deleted'|translate}";
  window.actionInfos_user_edited = "{'%d user edited'|translate}";
  window.actionInfos_user_logged_in = "{'%d user logged in'|translate}";
  window.actionInfos_user_logged_out = "{'%d user logged out'|translate}";
  window.actionInfos_users_added = "{'%d users added'|translate}";
  window.actionInfos_users_deleted = "{'%d users deleted'|translate}";
  window.actionInfos_users_edited = "{'%d users edited'|translate}";
  window.actionInfos_users_logged_in = "{'%d users logged in'|translate}";
  window.actionInfos_users_logged_out = "{'%d users logged out'|translate}";

  window.actionInfos_photo_added = "{'%d photo added'|translate}";
  window.actionInfos_photo_deleted = "{'%d photo deleted'|translate}";
  window.actionInfos_photo_edited = "{'%d photo edited'|translate}";
  window.actionInfos_photo_moved = "{'%d photo moved'|translate}";
  window.actionInfos_photos_added = "{'%d photos added'|translate}";
  window.actionInfos_photos_deleted = "{'%d photos deleted'|translate}";
  window.actionInfos_photos_edited = "{'%d photos edited'|translate}";
  window.actionInfos_photos_moved = "{'%d photos moved'|translate}";

  window.actionInfos_group_added = "{'%d group added'|translate}";
  window.actionInfos_group_deleted = "{'%d group deleted'|translate}";
  window.actionInfos_group_edited = "{'%d group edited'|translate}";
  window.actionInfos_group_moved = "{'%d group moved'|translate}";
  window.actionInfos_groups_added = "{'%d groups added'|translate}";
  window.actionInfos_groups_deleted = "{'%d groups deleted'|translate}";
  window.actionInfos_groups_edited = "{'%d groups edited'|translate}";
  window.actionInfos_groups_moved = "{'%d groups moved'|translate}";

  window.actionInfos_tag_added = "{'%d tag added'|translate}";
  window.actionInfos_tag_deleted = "{'%d tag deleted'|translate}";
  window.actionInfos_tag_edited = "{'%d tag edited'|translate}";
  window.actionInfos_tag_moved = "{'%d tag moved'|translate}";
  window.actionInfos_tags_added = "{'%d tags added'|translate}";
  window.actionInfos_tags_deleted = "{'%d tags deleted'|translate}";
  window.actionInfos_tags_edited = "{'%d tags edited'|translate}";
  window.actionInfos_tags_moved = "{'%d tags moved'|translate}";
</script>{/footer_script}

<div class="container">

    <div class="activity-header">
        <div class="select-user">
            <span class="select-user-title"> {'Selected user'|translate} </span>

            <select class="user-selector" placeholder="{'none'|translate}" single style="width:250px; height: 10px;">
                {foreach $ulist as $user}
                    <option value="{$user.id}"> <span class='username_filter'>{$user.username}</span><span
                            class='nb_lines_str'>
                            ({if $user.nb_lines == 1}{'%d Activity'|translate:$user.nb_lines}{else}{'%d Activities'|translate:$user.nb_lines}{/if})
                        </span></option>
                {/foreach}
            </select>

            <span class="icon-cancel cancel-icon"> </span>
        </div>
        <div class="activity-time">
            <span class="activity-time-text"> {'Activity time from'|translate}</span>
            <span class="start-date">
                <span class="icon-spin6 animate-spin"></span>
            </span>
            <span class="activity-time-text"> {'to'|translate}</span>
            <span class="end-date">
                <span class="icon-spin6 animate-spin"></span>
            </span>
        </div>
        <a class="download_csv tiptip" title="{'Download all activities'|translate}"
            href="admin.php?page=user_activity&type=download_logs">
            <i class="icon-download"> </i>
        </a>
    </div>
    {if max_page != 1}
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
    {/if}


    <div class="tab-title">
        <div class="action-title">
            {'Action'|translate}
        </div>

        <div class="date-title">
            {'Date'|translate}
        </div>

        <div class="user-title">
            {'User'|translate}
        </div>

        <div class="detail-title">
            {'Details'|translate}
        </div>
    </div>


    <div class="tab">
        <div class="loading">
            <span class="icon-spin6 animate-spin"> </span>
        </div>
        <div class="line hide" id="-1">
            <div class="action-section">
                <div class="action-type">
                    <span class="action-icon"></span>
                    <span class="action-name"> Edit </span>
                </div>
                <div class="action-infos">
                    <span class="action-infos-test"> T </span>
                </div>
            </div>

            <div class="date-section">
                <span class="icon-clock"> </span>
                <span class="date-day">1 Janvier 1970</span>
                <span class="date-hour">a 00:00</span>
            </div>

            <div class="user-section">
                <div class="user-pic">
                </div>
                <div class="user-name">
                    Username
                </div>
            </div>

            <div class="detail-section">
                <div class="detail-item detail-item-1">
                    detail 1
                </div>
                <div class=" detail-item detail-item-2">
                    detail 2
                </div>
                <div class="detail-item detail-item-3">
                    detail 3
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .container {
        padding: 0 30px;
    }

    .container,
    .tab {
        display: flex;
        flex-direction: column;
    }

    .tab-title {
        display: flex;
        flex-direction: row;
    }

    .hide {
        display: none !important;
    }

    .tab-title div {
        text-align: left;
        font-size: 1.1em;
        font-weight: bold;

        margin: 0 20px 10px 0px;

        color: #9e9e9e;

        padding-bottom: 5px;
    }

    .tab-title div:first-child {
        margin: 0 0 10px 35px;
    }

    .tab-title .action-title,
    .line .action-section {
        min-width: 320px;
        max-width: 340px;
    }

    .tab-title .action-title {
        min-width: 298px !important;
    }

    .tab-title .date-title,
    .line .date-section {
        min-width: 280px;
        max-width: 300px;
    }

    .tab-title .user-title,
    .line .user-section {
        min-width: 200px;
        max-width: 250px;
    }


    .line .action-section,
    .line .date-section,
    .line .user-section,
    .tab-title .action-title,
    .tab-title .date-title,
    .tab-title .user-title {
        text-align: left;
        {* width: 22%; *}
    }

    .line .action-section,
    .line .date-section,
    .line .user-section {
        margin: 0 20px 0 0;
    }

    .line .detail-section,
    .tab-title .detail-title {
        display: flex;
        flex-grow: 1;
        margin-right: 0;
    }

    .action-section {
        display: flex;
        flex-direction: row;
        align-items: center;
    }

    .action-type {
        margin: 0 10px 0 15px;
        padding: 3px 10px;
        border-radius: 20px;

        white-space: nowrap;
    }

    .action-infos {
        display: flex;
        flex-direction: row;
    }

    .action-infos span {
        margin-right: 5px;
    }

    .date-section .date-day {
        font-weight: bold;
    }

    .user-section {
        display: flex;
        flex-direction: row;
        align-items: center;
    }

    .user-section .user-pic {
        width: 30px;
        height: 30px;

        min-width: 30px;

        border-radius: 50%;

        margin-right: 10px;

        display: flex;

        justify-content: center;
        align-items: center;

        font-weight: 600;
        font-size: 17px;
    }

    .user-section .user-name {
        font-weight: bold;
    }

    /* Activity Header */

    .activity-header {
        display: flex;
        flex-direction: row;

        align-items: center;

        height: 100px;
        width: 100%;
    }

    .select-user span {
        font-size: 15px;
        font-weight: bold;

        margin-right: 20px;
    }

    .activity-time {
        margin: 0 25px;
    }

    .user-selector {
        width: 150px;
    }


    /* TomSelect */
    .ts-wrapper.single.user-selector {
        height: 30px;
    }

    .ts-wrapper.single .ts-control {
        height: 30px;
        padding: 0 10px;

        display: flex;
        align-items: center;
        justify-content: left;
    }

    .ts-control {
        text-align: left;
    }

    .ts-wrapper.single .ts-control input {
        height: 30px;
    }

    .ts-dropdown {
        text-align: left;
    }

    .cancel-icon {
        margin: 0 0 0 10px !important;

        cursor: pointer;
    }

    .loading {
        font-size: 25px;
    }

    .action-section::before {
        margin: 0 -5px 0 10px;
        opacity: 0.6;
    }
</style>