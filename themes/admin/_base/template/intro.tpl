<script id="pwg-page-data" type="application/json">{$page_data_json}</script>
{combine_script id='intro_tooltips' load='footer' path='themes/admin/_base/js/intro_tooltips.js'}

{combine_css path="themes/admin/_base/css/pages/intro.css"}

<h2>{'Piwigo Administration'|@translate}</h2>

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
<span class="number">{$NB_ALBUMS}</span><span class="caption">{'Albums'|translate}</span>
</a>
{/if}

{if $NB_TAGS > 1}
<a class="stat-box" href="{$U_TAGS}">
<i class="icon-tags icon-yellow"></i>
<span class="number">{$NB_TAGS}</span><span class="caption" title="{'%d associations'|translate:$NB_IMAGE_TAG}">{'Tags'|translate}</span>
</a>
{/if}

{if $NB_USERS > 2}
<a class="stat-box" href="{$U_USERS}">
<i class="icon-users icon-purple"></i>
{* -1 because we don't count the "guest" user *}
<span class="number">{$NB_USERS - 1}</span><span class="caption">{'Users'|translate}</span>
</a>
{/if}

{if $NB_GROUPS > 0}
<a class="stat-box" href="{$U_GROUPS}">
<i class="icon-group icon-purple"></i>
<span class="number">{$NB_GROUPS}</span><span class="caption">{'Groups'|translate}</span>
</a>
{/if}

{if $NB_COMMENTS > 1}
<a class="stat-box" href="{$U_COMMENTS}">
<i class="icon-chat icon-blue"></i>
<span class="number">{$NB_COMMENTS}</span><span class="caption">{'Comments'|translate}</span>
</a>
{/if}

{if $NB_RATES > 0}
<a class="stat-box" href="{$U_RATING}">
<i class="icon-star icon-yellow"></i>
<span class="number">{$NB_RATES}</span><span class="caption">{'Rating'|translate}</span>
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

  <div class="chart-title"> {"Activity peak in the last weeks"|@translate}</div>
  <div class="activity-chart" style="--chart-rows:{count($ACTIVITY_CHART_DATA) + 1}">
    {foreach from=$ACTIVITY_CHART_DATA item=WEEK_ACTIVITY key=WEEK_NUMBER}
      <div id="week-{$WEEK_NUMBER}-legend" class="row-legend"><div>{'Week %d'|@translate:$ACTIVITY_WEEK_NUMBER[$WEEK_NUMBER]}</div></div>
      {foreach from=$WEEK_ACTIVITY item=SIZE key=DAY_NUMBER}
        <span class="activity_tooltips">
          {if $SIZE != 0}
          {assign var='SIZE_IN_UNIT' value=$SIZE/$ACTIVITY_CHART_NUMBER_SIZES * 5 + 1}
          {assign var='OPACITY_IN_UNIT' value=$SIZE/$ACTIVITY_CHART_NUMBER_SIZES * 0.6 + 0.2}
          <div id="day{$WEEK_NUMBER}-{$DAY_NUMBER}" class="activity-day" style="--day-size:{$SIZE_IN_UNIT}vw"></div>
          {if $ACTIVITY_LAST_WEEKS[$WEEK_NUMBER][$DAY_NUMBER]["number"] != 0}     
          <p class="tooltip" style="--tooltip-y:{$SIZE_IN_UNIT/2}vw">
            <span class="tooltip-arrow"></span>
            <span class="tooltip-header"> 
              <span class="tooltip-title">{if $ACTIVITY_LAST_WEEKS[$WEEK_NUMBER][$DAY_NUMBER]["number"] > 1}{'%d Activities'|translate:$ACTIVITY_LAST_WEEKS[$WEEK_NUMBER][$DAY_NUMBER]["number"]}{else}{'%d Activity'|translate:$ACTIVITY_LAST_WEEKS[$WEEK_NUMBER][$DAY_NUMBER]["number"]}{/if}</span>
              <span class="tooltip-date">{$ACTIVITY_LAST_WEEKS[$WEEK_NUMBER][$DAY_NUMBER]["date"]}</span>
            </span>
            <span class="tooltip-details">
            {foreach from=$ACTIVITY_LAST_WEEKS[$WEEK_NUMBER][$DAY_NUMBER]["details"] item=actions key=cat}
              <span class="tooltip-details-cont">
                {if $cat == "Group"} <span class="icon-group icon-purple tooltip-details-title">{$cat|translate}</span>
                {elseif $cat == "User"} <span class="icon-users icon-purple tooltip-details-title"> {$cat|translate}</span>
                {elseif $cat == "Album"} <span class="icon-sitemap icon-red tooltip-details-title">{$cat|translate}</span>
                {elseif $cat == "Photo"} <span class="icon-picture icon-yellow tooltip-details-title">{$cat|translate} </span>
                {elseif $cat == "Tag"} <span class="icon-tags icon-green tooltip-details-title">{$cat|translate} </span>
                {else} <span class="tooltip-details-title"> {$cat|translate} </span> {/if}

                {foreach from=$actions item=number key=action}
                  {if $action == "Edit"} <span class="icon-pencil tooltip-detail" title="{$number|translate_dec:'%d edition':'%d editions'}">{$number}</span>
                  {elseif $action == "Add"} <span class="icon-plus tooltip-detail" title="{$number|translate_dec:'%d addition':'%d additions'}">{$number}</span>
                  {elseif $action == "Delete"} <span class="icon-trash tooltip-detail" title="{$number|translate_dec:'%d deletion':'%d deletions'}">{$number}</span>
                  {elseif $action == "Login"} <span class="icon-key tooltip-detail" title="{$number|translate_dec:'%d login':'%d logins'}">{$number}</span>
                  {elseif $action == "Logout"} <span class="icon-logout tooltip-detail" title="{$number|translate_dec:'%d logout':'%d logouts'}">{$number} </span>
                  {elseif $action == "Move"} <span class="icon-move tooltip-detail" title="{$number|translate_dec:'%d movement':'%d movements'}">{$number} </span>
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
    {foreach from=$DAY_LABELS item=day}
      <div class="col-legend">{$day} <div class="line-vertical" style="--line-h:{count($ACTIVITY_CHART_DATA)*100 - 50}%"></div></div>
    {/foreach}
  </div>

  <div id="chart-title-storage" class="chart-title"> {'Storage'|translate} <span class="chart-title-infos"> {'%s MB used'|translate:(round($STORAGE_TOTAL/1000, 0))} </span></div>

  <div class="storage-chart">
    {foreach from=$STORAGE_CHART_DATA key=type item=details}
      <span data-type="storage-{$type}" style="--storage-w:{if $STORAGE_TOTAL > 0}{$details.total.filesize/$STORAGE_TOTAL*100}{else}0{/if}%">
        <p>{if $STORAGE_TOTAL > 0}{round($details.total.filesize/$STORAGE_TOTAL*100)}{else}0{/if}%</p>
      </span>  
    {/foreach}
  </div>

  <div class="storage-tooltips">
    {foreach from=$STORAGE_CHART_DATA key=type item=value}
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
    {foreach from=$STORAGE_CHART_DATA item=i key=type}
      <div><span></span> <p>{$type|translate}</p></div>
    {/foreach}
  </div>

</div> {* .intro-chart *}

</div> {* .intro-page-container *}

<p class="showCreateAlbum">
{if $ENABLE_SYNCHRONIZATION}
  <a href="{$U_QUICK_SYNC}" class="icon-exchange">{'Quick Local Synchronization'|translate}</a>
{/if}


</p>