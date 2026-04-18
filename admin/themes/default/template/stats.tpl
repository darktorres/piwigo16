{footer_script}<script>
  window.str_number_page_visited = "{'Page Visited'|translate}";
  window.str_number_page_visited_with_year = "{'Page Visited in %s'|translate}";
  window.str_avg = "{'Average last 12 months'|translate}";
  window.str_months_tosplit = "{$month_labels}";
</script>{/footer_script}

{if $vite_stats}
<script type="module" src="/admin/themes/default/js/dist/{$vite_stats}"></script>
{/if}

<div class="stat-compare-mode">
  <label class="switch">
    <input type="checkbox" id="toggleCompareMode">
    <span class="slider round" checked="false"></span>
  </label>
  {'Compare mode'|translate}
</div>

<div id="data" data-hours='{json_encode($lastHours)}' data-days='{json_encode($lastDays)}'
  data-months='{json_encode($lastMonths)}' data-years='{json_encode($lastYears)}'
  data-compare-years='{json_encode($compareYears)}' data-month-stats='{json_encode($monthStats)}'></div>
<div class="stat-legend-container">
  <div class="stat-data-selector">
    <input type="radio" id="hours-selector" name="stat-data-type">
    <label for="hours-selector" data-value="hours">{"Hour"|translate}</label>
    <input type="radio" id="days-selector" name="stat-data-type" checked>
    <label for="days-selector" data-value="days">{"Day"|translate}</label>
    <input type="radio" id="months-selector" name="stat-data-type">
    <label for="months-selector" data-value="months">{"Month"|translate}</label>
    <input type="radio" id="years-selector" name="stat-data-type">
    <label for="years-selector" data-value="years">{"Year"|translate}</label>
  </div>
</div>

<div class="stat-graph-container">
  <canvas id="stat-graph" width="400" height="150" role="img">
    <p>Your browser does not support the canvas element.</p>
  </canvas>
</div>