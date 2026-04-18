document.addEventListener('DOMContentLoaded', function() {
  const cfg = JSON.parse(document.getElementById('pwg-intro-data')?.textContent || '{}');

  // Cluetip tooltips
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

  // Check for updates
  if (cfg.CHECK_FOR_UPDATES) {
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
          if (warningsUl) warningsUl.insertAdjacentHTML('beforeend', '<li>' + cfg.piwigo_need_update_msg + '</li>');
        }
        if (ext_update) {
          var warningsUl = document.querySelector(".warnings ul");
          if (warningsUl) warningsUl.insertAdjacentHTML('beforeend', '<li>' + cfg.ext_need_update_msg + '</li>');
        }
      });
  }

  // Newsletter subscription
  document.querySelectorAll('.newsletter-subscription a').forEach(function(el) {
    el.addEventListener('click', function(event) {
      document.querySelectorAll('.newsletter-subscription').forEach(function(ns) { ns.style.display = 'none'; });
      fetch('admin.php?action=hide_newsletter_subscription');
      if (this.classList.contains('newsletter-hide')) {
        event.preventDefault();
      }
    });
  });

  // Storage chart
  let size_info = cfg.storage_total > 1000000 ? cfg.str_gb_used : cfg.str_mb_used;
  let size_nb = cfg.storage_total > 1000000 ? (cfg.storage_total / 1000000).toFixed(2) : (cfg.storage_total / 1000).toFixed(0);
  var chartTitleInfos = document.querySelector(".chart-title-infos");
  if (chartTitleInfos) chartTitleInfos.innerHTML = size_info.replace("%s", size_nb);

  let translate_type = {};
  Object.keys(cfg.storage_details).forEach(function(type) {
    translate_type[type] = cfg.storage_details[type]._translate || type;
  });

  Object.entries(cfg.storage_details).forEach(([type, infos]) => {
    let size = infos.total.filesize;
    let str_size_type_string = size > 1000000 ? cfg.str_gb : cfg.str_mb;
    let size_nb = size > 1000000 ? (size / 1000000).toFixed(2) : (size / 1000).toFixed(0);
    let str_size = str_size_type_string.replace("%s", size_nb);

    var storageTitleEl = document.getElementById('storage-title-' + type);
    if (storageTitleEl) storageTitleEl.innerHTML = '<b>' + translate_type[type] + '</b>';
    var storageSizeEl = document.getElementById('storage-size-' + type);
    if (storageSizeEl) storageSizeEl.innerHTML = '<b>' + str_size + '</b>';
    var storageFilesEl = document.getElementById('storage-files-' + type);
    if (storageFilesEl) storageFilesEl.innerHTML = '<p>' + (infos.total.nb_files ? cfg.translate_files.replace('%d', infos.total.nb_files) : "~") + '</p>';

    if (infos.details) {
      Object.keys(infos.details).forEach(function(ext) {
        var data = infos.details[ext];
        let detail_size = data.filesize;
        let detail_str_size_type_string;
        let detail_size_nb = 0;
        if (detail_size > 1000000) {
          detail_str_size_type_string = cfg.str_gb;
          detail_size_nb = (detail_size / 1000000).toFixed(2);
        } else {
          detail_str_size_type_string = cfg.str_mb;
          detail_size_nb = (detail_size / 1000).toFixed(0) < 1 ? (detail_size / 1000).toFixed(2) : (detail_size / 1000).toFixed(0);
        }
        let detail_str_size = detail_str_size_type_string.replace("%s", detail_size_nb);
        var storageDetailEl = document.getElementById('storage-detail-' + type);
        if (storageDetailEl) storageDetailEl.insertAdjacentHTML('beforeend',
          '<span class="tooltip-details-cont">' +
          '<span class="tooltip-details-ext"><b>' + ext + '</b></span>' +
          '<span class="tooltip-details-size"><b>' + detail_str_size + '</b></span>' +
          '<span class="tooltip-details-files">' + cfg.translate_files.replace('%d', data.nb_files) + '</span>' +
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
    var chartTitleEl = document.getElementById('chart-title-storage');
    let storage_width = chartTitleEl ? chartTitleEl.clientWidth : 0;
    if (left + tooltip.clientWidth > storage_width) {
      let diff = (left + tooltip.clientWidth) - storage_width;
      left = left - diff;
      arrow.style.left = 'calc(50% + ' + diff + 'px)';
    }
    tooltip.style.left = left + "px";
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
});
