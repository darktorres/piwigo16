{footer_script}<script>
  var piwigo_need_update_msg = '<a href="admin.php?page=updates">{'A new version of Piwigo is available.'|translate|escape:"javascript"} <i class="icon-right"></i></a>';
  var ext_need_update_msg = '<a href="admin.php?page=updates&amp;tab=ext">{'Some upgrades are available for extensions.'|translate|escape:"javascript"} <i class="icon-right"></i></a>';
  const str_gb_used = "{'%s GB used'|translate}";
  const str_mb_used = "{'%s MB used'|translate}";
  const str_gb = "{'%sGB'|translate}".replace(' ', '&nbsp;');
  const str_mb = "{'%sMB'|translate}".replace(' ', '&nbsp;');
  const storage_total = {$STORAGE_TOTAL};
  const storage_details = {$STORAGE_CHART_DATA|json_encode};
  const translate_files = "{'%d files'|translate|escape:javascript}";
  let translate_type = {};

  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.cluetip').forEach(function(el) {
      var raw = el.getAttribute('title') || '';
      el.removeAttribute('title');
      el.dataset.cluetip = raw;
      el.addEventListener('mouseenter', function(e) {
        var parts = (el.dataset.cluetip || '').split('|');
        var tip = document.createElement('div');
        tip.className = 'pwg-cluetip';
        tip.style.cssText = 'position:absolute;z-index:9999;background:#fff;border:1px solid #ccc;padding:8px;width:300px;box-shadow:2px 2px 6px rgba(0,0,0,.2);font-size:13px;';
        tip.innerHTML = '<strong>' + (parts[0] || '') + '</strong>' + (parts[1] ? '<hr style="margin:4px 0">' + parts[1] : '');
        document.body.appendChild(tip);
        var rect = el.getBoundingClientRect();
        tip.style.left = (rect.left + window.scrollX) + 'px';
        tip.style.top = (rect.bottom + window.scrollY + 4) + 'px';
        el._cluetip = tip;
      });
      el.addEventListener('mouseleave', function() {
        if (el._cluetip) { el._cluetip.remove(); el._cluetip = null; }
      });
    });
    {if $CHECK_FOR_UPDATES}
      fetch('ws.php?method=pwg.extensions.checkUpdates&format=json')
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data['stat'] != 'ok') return;
          var piwigo_update = data['result']['piwigo_need_update'];
          var ext_update = data['result']['ext_need_update'];
          if (piwigo_update || ext_update) {
            var warningsEl = document.querySelector(".warnings");
            if (!warningsEl || warningsEl.tagName !== 'DIV') {
              var eiwEl = document.querySelector(".eiw");
              if (eiwEl) eiwEl.insertAdjacentHTML('afterbegin',
                '<div class="warnings"><i class="eiw-icon icon-attention"></i><ul></ul></div>');
            }
          }
          if (piwigo_update) {
            var warningsUl = document.querySelector(".warnings ul");
            if (warningsUl) warningsUl.insertAdjacentHTML('beforeend', '<li>' + piwigo_need_update_msg + '</li>');
          }
          if (ext_update) {
            var warningsUl = document.querySelector(".warnings ul");
            if (warningsUl) warningsUl.insertAdjacentHTML('beforeend', '<li>' + ext_need_update_msg + '</li>');
          }
        });
    {/if}

    document.querySelectorAll('.newsletter-subscription a').forEach(function(el) {
      el.addEventListener('click', function(event) {
        document.querySelectorAll('.newsletter-subscription').forEach(function(ns) { ns.style.display = 'none'; });
        fetch('admin.php?action=hide_newsletter_subscription');
        if (this.classList.contains('newsletter-hide')) {
          event.preventDefault();
        }
      });
    });

    let size_info = storage_total > 1000000 ? str_gb_used : str_mb_used;
    let size_nb = storage_total > 1000000 ? (storage_total / 1000000).toFixed(2) : (storage_total / 1000).toFixed(0);
    var chartTitleInfos = document.querySelector(".chart-title-infos");
    if (chartTitleInfos) chartTitleInfos.innerHTML = size_info.replace("%s", size_nb);
  });

  {foreach from=$STORAGE_CHART_DATA key=type_to_translate item=details}
    translate_type['{$type_to_translate}'] = "{$type_to_translate|translate}";
  {/foreach}

  Object.entries(storage_details).forEach(([type, infos]) => {
    // Determine if we use MB or GB and show it correctly
    let size = infos.total.filesize;
    let str_size_type_string = size > 1000000 ? str_gb : str_mb;
    let size_nb = size > 1000000 ? (size / 1000000).toFixed(2) : (size / 1000).toFixed(0);
    let str_size = str_size_type_string.replace("%s", size_nb);

    // Display head of Tooltip
    var storageTitleEl = document.getElementById('storage-title-' + type);
    if (storageTitleEl) storageTitleEl.innerHTML = '<b>' + translate_type[type] + '</b>';
    var storageSizeEl = document.getElementById('storage-size-' + type);
    if (storageSizeEl) storageSizeEl.innerHTML = '<b>' + str_size + '</b>';
    var storageFilesEl = document.getElementById('storage-files-' + type);
    if (storageFilesEl) storageFilesEl.innerHTML = '<p>' + (infos.total.nb_files ? translate_files.replace('%d', infos.total.nb_files) : "~") + '</p>';

    // Display body of Tooltip
    if (infos.details) {
      Object.keys(infos.details).forEach(function(ext) {
        var data = infos.details[ext];
        // Determinate if we use MB or GB and show it correctly (duplicate code from total size for scaling code)
        let detail_size = data.filesize;
        let detail_str_size_type_string;
        let detail_size_nb = 0;
        if (detail_size > 1000000) {
          detail_str_size_type_string = str_gb;
          detail_size_nb = (detail_size / 1000000).toFixed(2);
        } else {
          detail_str_size_type_string = str_mb;
          detail_size_nb = (detail_size / 1000).toFixed(0) < 1 ? (detail_size / 1000).toFixed(2) : (
            detail_size / 1000).toFixed(0);
        }
        let detail_str_size = detail_str_size_type_string.replace("%s", detail_size_nb);
        var storageDetailEl = document.getElementById('storage-detail-' + type);
        if (storageDetailEl) storageDetailEl.insertAdjacentHTML('beforeend',
          '<span class="tooltip-details-cont">' +
          '<span class="tooltip-details-ext"><b>' + ext + '</b></span>' +
          '<span class="tooltip-details-size"><b>' + detail_str_size + '</b></span>' +
          '<span class="tooltip-details-files">' + translate_files.replace('%d', data.nb_files) + '</span>' +
          '</span>');
        var chartSpan = document.querySelector('.storage-chart span[data-type="storage-' + type + '"]');
        var ext_bg_color = chartSpan ? window.getComputedStyle(chartSpan).backgroundColor : '';
        document.querySelectorAll('#storage-' + type + ' .tooltip-details-ext b').forEach(function(b) {
          b.style.color = ext_bg_color;
        });
      });
    } else {
      document.querySelectorAll('#storage-' + type + ' .separated').forEach(function(el) {
        el.setAttribute('style', 'display: none !important');
      });
      document.querySelectorAll('#storage-' + type + ' .tooltip-header').forEach(function(el) {
        el.style.margin = '0';
      });
    }

    // Fixing storage chart tooltip bug in little screen
    // Keep showing tooltip and his % when hovered
    var tooltipEl = document.getElementById('storage-' + type);
    if (tooltipEl) {
      tooltipEl.addEventListener('mouseenter', function() {
        this.style.display = 'block';
        document.querySelectorAll('.storage-chart span[data-type="storage-' + type + '"] p').forEach(function(p) { p.style.opacity = '0.4'; });
      });
      tooltipEl.addEventListener('mouseleave', function() {
        this.style.display = 'none';
        document.querySelectorAll('.storage-chart span[data-type="storage-' + type + '"] p').forEach(function(p) { p.style.opacity = '0'; });
      });
    }
    document.querySelectorAll('.storage-chart span[data-type="storage-' + type + '"]').forEach(function(span) {
      span.addEventListener('mouseenter', function() {
        this.querySelectorAll('p').forEach(function(p) { p.style.opacity = '0.4'; });
      });
      span.addEventListener('mouseleave', function() {
        this.querySelectorAll('p').forEach(function(p) { p.style.opacity = '0'; });
      });
    });
  });

  function positionStorageTooltip(el) {
    var tooltipId = el.dataset.type;
    var tooltip = document.querySelector('.storage-tooltips #' + tooltipId);
    var arrow = document.querySelector('.storage-tooltips #' + tooltipId + ' .tooltip-arrow');
    if (!tooltip || !arrow) return;

    let left = el.offsetLeft + el.getBoundingClientRect().width / 2 - tooltip.clientWidth / 2;
    // Move tooltip if it creates horizontal scrollbar
    var chartTitleEl = document.getElementById('chart-title-storage');
    let storage_width = chartTitleEl ? chartTitleEl.clientWidth : 0;
    if (left + tooltip.clientWidth > storage_width) {
      let diff = (left + tooltip.clientWidth) - storage_width;
      left = left - diff;
      arrow.style.left = 'calc(50% + ' + diff + 'px)';
    }
    tooltip.style.left = left + "px";
    // Move tooltip if it creates vertical scrollbar
    var storageChart = document.querySelector('.storage-chart');
    let str_chart_pos = storageChart ? storageChart.getBoundingClientRect().top + window.scrollY : 0;
    let str_chart_height = storageChart ? storageChart.clientHeight : 0;
    let tooltip_height = tooltip.clientHeight + str_chart_height;
    if (str_chart_pos + tooltip_height > window.innerHeight) {
      tooltip.style.bottom = 'calc(100% + ' + str_chart_height + 'px)';
      arrow.classList.add('bottom');
    } else {
      tooltip.style.bottom = '';
      arrow.classList.remove('bottom');
    }
  }

  //Tooltip for the storage chart
  document.querySelectorAll('.storage-chart span').forEach(function(el) {
    var tooltipId = el.dataset.type;
    var tooltip = document.querySelector('.storage-tooltips #' + tooltipId);
    positionStorageTooltip(el);
    if (tooltip) {
      var toggleFn = function() {
        tooltip.style.display = tooltip.style.display === 'none' ? '' : 'none';
      };
      el.addEventListener('mouseenter', toggleFn);
      el.addEventListener('mouseleave', toggleFn);
    }
  });

  window.addEventListener('resize', function() {
    document.querySelectorAll('.storage-chart span').forEach(positionStorageTooltip);
  });
</script>{/footer_script}

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