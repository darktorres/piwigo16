{combine_css path='admin/themes/default/css/pages/user_activity.css' order=-10}
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

