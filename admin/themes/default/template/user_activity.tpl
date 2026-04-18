{combine_css path='node_modules/tom-select/dist/css/tom-select.default.css'}
{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}

{if $vite_user_activity}
<script type="module" src="admin/themes/default/js/dist/{$vite_user_activity}"></script>
{/if}

<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

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