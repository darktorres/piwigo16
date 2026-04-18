{if $vite_intro}
<script type="module" src="admin/themes/default/js/dist/{$vite_intro}"></script>
{/if}

<script id="pwg-intro-data" type="application/json">
{ldelim}
  "CHECK_FOR_UPDATES": {if $CHECK_FOR_UPDATES}true{else}false{/if},
  "piwigo_need_update_msg": "<a href=\"admin.php?page=updates\">{'A new version of Piwigo is available.'|translate|escape:'javascript'} <i class=\"icon-right\"></i></a>",
  "ext_need_update_msg": "<a href=\"admin.php?page=updates&amp;tab=ext\">{'Some upgrades are available for extensions.'|translate|escape:'javascript'} <i class=\"icon-right\"></i></a>",
  "str_gb_used": "{'%s GB used'|translate}",
  "str_mb_used": "{'%s MB used'|translate}",
  "str_gb": "{'%sGB'|translate}",
  "str_mb": "{'%sMB'|translate}",
  "storage_total": {$STORAGE_TOTAL},
  "storage_details": {$STORAGE_CHART_DATA|json_encode},
  "translate_files": "{'%d files'|translate|escape:javascript}"
{rdelim}
</script>

{html_style}<style>
  .eiw .messages ul li {
    list-style-type: none !important;
  }

  .eiw .messages .eiw-icon {
    margin-right: 10px !important;
  }
</style>{/html_style}

<h2>{'Piwigo Administration'|translate}</h2>

<div class="intro-page-container">
  <div class="stat-boxes">

    {if $NB_PHOTOS > 1}
      <a class="stat-box" href="{$U_ADD_PHOTOS}">
        <i class="icon-picture icon-yellow"></i>
        <span class="number">{$NB_PHOTOS|number_format}</span><span class="caption">{'Photos'|translate}</span>
      </a>
    {/if}

    {if $NB_ALBUMS > 1}
      <a class="stat-box" href="{$U_ALBUMS}">
        <i class="icon-sitemap icon-red"></i>
        <span class="number">{$NB_ALBUMS|number_format}</span><span class="caption">{'Albums'|translate}</span>
      </a>
    {/if}

    {if $NB_TAGS > 1}
      <a class="stat-box" href="{$U_TAGS}">
        <i class="icon-tags icon-yellow"></i>
        <span class="number">{$NB_TAGS|number_format}</span><span class="caption"
          title="{'%d associations'|translate:$NB_IMAGE_TAG}">{'Tags'|translate}</span>
      </a>
    {/if}

    {if $NB_USERS > 2}
      <a class="stat-box" href="{$U_USERS}">
        <i class="icon-users icon-purple"></i>
        {* -1 because we don't count the "guest" user *}
        <span class="number">{($NB_USERS - 1)|number_format}</span><span class="caption">{'Users'|translate}</span>
      </a>
    {/if}

    {if $NB_GROUPS > 0}
      <a class="stat-box" href="{$U_GROUPS}">
        <i class="icon-group icon-purple"></i>
        <span class="number">{$NB_GROUPS|number_format}</span><span class="caption">{'Groups'|translate}</span>
      </a>
    {/if}

    {if $NB_COMMENTS > 1}
      <a class="stat-box" href="{$U_COMMENTS}">
        <i class="icon-chat icon-blue"></i>
        <span class="number">{$NB_COMMENTS|number_format}</span><span class="caption">{'Comments'|translate}</span>
      </a>
    {/if}

    {if $NB_RATES > 0}
      <a class="stat-box" href="{$U_RATING}">
        <i class="icon-star icon-yellow"></i>
        <span class="number">{$NB_RATES|number_format}</span><span class="caption">{'Rating'|translate}</span>
      </a>
    {/if}

    {if $NB_VIEWS > 0}
      <a class="stat-box" href="{$U_HISTORY_STAT}">
        <i class="icon-signal icon-blue"></i>
        <span class="number">{$NB_VIEWS}</span><span class="caption">{'Pages seen'|translate}</span>
      </a>
    {/if}

    {if $NB_PLUGINS > 0}
      <a class="stat-box" href="{$U_PLUGINS}">
        <i class="icon-puzzle icon-green"></i>
        <span class="number">{$NB_PLUGINS}</span><span class="caption">{'Plugins'|translate}</span>
      </a>
    {/if}

    <div class="stat-box">
      <i class="icon-hdd icon-blue"></i>
      <span class="number">{$STORAGE_USED}</span><span class="caption">{'Storage used'|translate}</span>
    </div>

  </div> {* .stat-boxes *}

  <div class="intro-charts">

    <div class="chart-title"> {"Activity peak in the last weeks"|translate}</div>
    <div class="activity-chart" style="grid-template-rows: repeat({count($ACTIVITY_CHART_DATA) + 1}, 5vw);">
      {foreach $ACTIVITY_CHART_DATA as $WEEK_NUMBER => $WEEK_ACTIVITY}
        <div id="week-{$WEEK_NUMBER}-legend" class="row-legend">
          <div>{'Week %d'|translate:$ACTIVITY_WEEK_NUMBER[$WEEK_NUMBER]}</div>
        </div>
        {foreach $WEEK_ACTIVITY as $DAY_NUMBER => $SIZE}
          <span>
            {if $SIZE != 0}
              {assign var='SIZE_IN_UNIT' value=$SIZE/$ACTIVITY_CHART_NUMBER_SIZES * 5 + 1}
              {assign var='OPACITY_IN_UNIT' value=$SIZE/$ACTIVITY_CHART_NUMBER_SIZES * 0.6 + 0.2}
              <div id="day{$WEEK_NUMBER}-{$DAY_NUMBER}" style="height:{$SIZE_IN_UNIT}vw;width:{$SIZE_IN_UNIT}vw;"></div>
              {if $ACTIVITY_LAST_WEEKS[$WEEK_NUMBER][$DAY_NUMBER]["number"] != 0}
                <p class="tooltip" style="transform: translate(-50%,{$SIZE_IN_UNIT/2}vw);">
                  <span class="tooltip-header">
                    <span
                      class="tooltip-title">{if $ACTIVITY_LAST_WEEKS[$WEEK_NUMBER][$DAY_NUMBER]["number"] > 1}{'%d Activities'|translate:$ACTIVITY_LAST_WEEKS[$WEEK_NUMBER][$DAY_NUMBER]["number"]}{else}{'%d Activity'|translate:$ACTIVITY_LAST_WEEKS[$WEEK_NUMBER][$DAY_NUMBER]["number"]}{/if}</span>
                    <span class="tooltip-date">{$ACTIVITY_LAST_WEEKS[$WEEK_NUMBER][$DAY_NUMBER]["date"]}</span>
                  </span>
                  <span class="tooltip-details">
                    {foreach $ACTIVITY_LAST_WEEKS[$WEEK_NUMBER][$DAY_NUMBER]["details"] as $cat => $actions}
                      <span class="tooltip-details-cont">
                        {if $cat == "Group"} <span class="icon-group icon-purple tooltip-details-title">{$cat|translate}</span>
                        {elseif $cat == "User"} <span class="icon-users icon-purple tooltip-details-title">
                            {$cat|translate}</span>
                        {elseif $cat == "Album"} <span class="icon-sitemap icon-red tooltip-details-title">{$cat|translate}</span>
                        {elseif $cat == "Photo"} <span class="icon-picture icon-yellow tooltip-details-title">{$cat|translate}
                          </span>
                        {elseif $cat == "Tag"} <span class="icon-tags icon-green tooltip-details-title">{$cat|translate} </span>
                        {else} <span class="tooltip-details-title"> {$cat|translate} </span>
                        {/if}

                        {foreach $actions as $action => $number}
                          {if $action == "Edit"} <span class="icon-pencil tooltip-detail"
                              title="{"%s editions"|translate:$number}">{$number}</span>
                          {elseif $action == "Add"} <span class="icon-plus tooltip-detail"
                              title="{"%s additions"|translate:$number}">{$number}</span>
                          {elseif $action == "Delete"} <span class="icon-trash tooltip-detail"
                              title="{"%s deletions"|translate:$number}">{$number}</span>
                          {elseif $action == "Login"} <span class="icon-key tooltip-detail"
                              title="{"%s login"|translate:$number}">{$number}</span>
                          {elseif $action == "Logout"} <span class="icon-logout tooltip-detail"
                              title="{"%s logout"|translate:$number}">{$number} </span>
                          {elseif $action == "Move"} <span class="icon-move tooltip-detail"
                              title="{"%s movement"|translate:$number}">{$number} </span>
                          {else} <span> ({$action|translate}) {$number} </span>
                          {/if}
                        {/foreach}
                      </span>
                    {/foreach}
                </p>
              {/if}
            {/if}
          </span>
        {/foreach}
      {/foreach}
      <div></div>
      {foreach $DAY_LABELS as $day}
        <div class="col-legend">{$day} <div class="line-vertical"
            style="height: {count($ACTIVITY_CHART_DATA)*100 - 50}%;"></div>
        </div>
      {/foreach}
    </div>

    <div id="chart-title-storage" class="chart-title"> {'Storage'|translate} <span class="chart-title-infos">
        {'%s MB used'|translate:(round($STORAGE_TOTAL/1000, 0))} </span></div>

    <div class="storage-chart">
      {foreach $STORAGE_CHART_DATA as $type => $details}
        {if $STORAGE_TOTAL > 0}
          {assign var=percent value=$details.total.filesize / $STORAGE_TOTAL * 100}
        {else}
          {assign var=percent value=0}
        {/if}
        <span data-type="storage-{$type}" style="width:{$percent}%">
          <p>{round($percent)}%</p>
        </span>
      {/foreach}
    </div>

    <div class="storage-tooltips">
      {foreach $STORAGE_CHART_DATA as $type => $value}
        <p id="storage-{$type}" class="tooltip">
          <span class="tooltip-arrow"></span>
          <span class="tooltip-header">
            <span id="storage-title-{$type}" class="tooltip-title"></span>
            <span id="storage-size-{$type}" class="tooltip-size"></span>
            <span id="storage-files-{$type}" class="tooltip-files"></span>
          </span>
          <span class="separated"></span>
          <span id="storage-detail-{$type}" class="tooltip-details"></span>
        </p>
      {/foreach}
    </div>

    <div class="storage-chart-legend">
      {foreach $STORAGE_CHART_DATA as $type => $i}
        <div><span></span>
          <p>{$type|translate}</p>
        </div>
      {/foreach}
    </div>

  </div> {* .intro-chart *}

</div> {* .intro-page-container *}

<p class="showCreateAlbum">
  {if $ENABLE_SYNCHRONIZATION}
    <a href="{$U_QUICK_SYNC}" class="icon-exchange">{'Quick Local Synchronization'|translate}</a>
  {/if}


  {if isset($SUBSCRIBE_BASE_URL)}
    <br><span class="newsletter-subscription"><a href="{$SUBSCRIBE_BASE_URL}{$EMAIL}" id="newsletterSubscribe"
        class="externalLink cluetip icon-mail-alt"
        title="{'Piwigo Announcements Newsletter'|translate}|{'Keep in touch with Piwigo project, subscribe to Piwigo Announcement Newsletter. You will receive emails when a new release is available (sometimes including a security bug fix, it\'s important to know and upgrade) and when major events happen to the project. Only a few emails a year.'|translate|htmlspecialchars|nl2br}">{'Subscribe %s to Piwigo Announcements Newsletter'|translate:$EMAIL}</a>
      <a href="#" class="newsletter-hide">{'... or hide this link'|translate}</a></span>
  {/if}
</p>