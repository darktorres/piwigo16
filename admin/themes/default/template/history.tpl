
{include file='include/datepicker.inc.tpl'}

<script id="pwg-page-data" type="application/json">{$page_data_json}</script>
{footer_script}
document.querySelectorAll('[data-datepicker]').forEach(function(el) {
  window.pwgDatepicker(el, {});
});
{/footer_script}

{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_script id='history' load='footer' path='admin/themes/default/js/history.js'}

{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}
{combine_css path="themes/default/vendor/fontello/css/gallery-icon.css" order=-10}

<form class="filter" method="post" name="filter" action="">
<fieldset class="history-filter">
  <div class="selectable-filter">
    <div class="filter-part date-start">
      <label>{'Start-Date'|@translate}</label>
        <input type="hidden" name="start" value="{$START}">
        <label>
          <input type="text" data-datepicker="start" data-datepicker-end="end" data-datepicker-unset="start_unset" readonly>
        </label>
        <a href="#" class="icon-cancel-circled" id="start_unset">{'unset'|translate}</a>
    </div>
    <div class="filter-part date-end">
      <label>{'End-Date'|@translate}</label>
        <input type="hidden" name="end" value="{$END}">
        <label>
          <input type="text" data-datepicker="end" data-datepicker-start="start" data-datepicker-unset="end_unset" readonly>
        </label>
        <a href="#" class="icon-cancel-circled" id="end_unset">{'unset'|translate}</a>
    </div>
    <div class="filter-part elem-type advanced-filter-select-container">
      <label>
        {'Action'|@translate}
        <select name="types[]" class="elem-type-select user-action-select advanced-filter-select">
          <option value=""></option>
          <option value="visited">{'Visited'|@translate} </option>
          <option value="downloaded">{'Downloaded'|@translate} </option>
        </select>
      </label>
    </div>
  </div>
  <div class="filter-tags">
    <label>{'Additional filters'|translate}</label>
    <div class="filter-container">
      <div id="default-filter" class="filter-item hide">
        <span class="filter-icon"></span><span class="filter-title"> test </span><span class="remove-filter icon-cancel"></span>
      </div>
    </div>
  </div>
  <div class="refresh-results icon-arrows-cw tiptip" title="{'Refresh'|translate}">
  </div>
</fieldset>
</form>

{if isset($search_summary)}
<fieldset>
  <legend>{'Summary'|@translate}</legend>

  <ul>
    <li>{$search_summary.NB_LINES}, {$search_summary.FILESIZE}</li>
    <li>
      {$search_summary.USERS}
      <ul>
        <li>{$search_summary.MEMBERS}</li>
        <li>{$search_summary.GUESTS}</li>
      </ul>
    </li>
  </ul>
</fieldset>
{/if}

{* Used to be copied in JS *}
<span id="-2" class="icon-green summary-user-item hide">
  <i class="icon-user-1"> </i>
  <i class="icon-plus-circled"> </i> 
  <span class="user-item-name"> User test </span>
</span>
{*  *}

<div class="search-summary">
  <div class="summary-lines">
    <span class="icon-yellow icon-menu summary-icons"></span>
    <span class="summary-data"> </span>
  </div>
  <div class="summary-weight">
    <span class="icon-purple icon-download summary-icons"></span>
    <span class="summary-data"> </span>
  </div>
  <div class="summary-users">
    <span class="icon-green icon-user-1 summary-icons"></span>
    <span class="summary-data"> </span>
    <div class="user-list">
    </div>
    <span class="user-dot icon-green summary-user-item">...</span>
  </div>
  <div class="summary-guests">
    <span class="icon-blue icon-user-secret summary-icons"></span>
    <span class="summary-data"> </span>
    <span class="addGuestFilter"> </span>
  </div>
</div>

{if !empty($navbar) }{include file='navigation_bar.tpl'|@get_extent:'navbar'}{/if}

<div class="loading hide"> 
  <span class="icon-spin6 animate-spin"> </span>
</div>
<div class="noResults">
  {'No results'|@translate}
</div>
<div class="container">
  <div class="tab-title">
    <div class="date-title">
        {'Date'|translate}
    </div>
    <div class="user-title">
        {'User'|translate}
    </div>
    <div class="type-title">
        {'Object'|translate}
    </div>
    <div class="detail-title">
        {'Details'|translate}
    </div>
  </div>

  {* Used to be copied in js *}
  <div class="search-line hide" id="-1">
      <div class="date-section">
        <i class="date-dwld-icon"> </i>
        <div class="date-infos">
          <span class="date-day bold"> July 4th, 2042 </span>
          <span> at <span class="date-hour">23:59:59</span> </span>
        </div>
      </div>

      <div class="user-section">
        <span class="user-name bold" title="{'Add as filter'|translate}"> Zac le boss <i class="add-filter icon-plus-circled"></i></span>
        <span class="user-ip" title="{'Add as filter'|translate}"> 192.168.0.0 <i class="add-filter icon-plus-circled"></i></span>
      </div>

      <div class="type-section">
        <a class="type-icon no-img" target="_blank"> <i class="icon-file-image"> </i> </a>
        <span class="icon-ellipsis-vert toggle-img-option">
          <div class="img-option">
            <a class="add-img-as-filter icon-filter"> {'Add as filter'|translate} </a>
            <a class="edit-img icon-pencil" href="" target="_blank">{'Edit'|@translate}</a>
          </div>
        </span>

        <div class="type-desc">
          <span class="type-name bold"> WIP </span>
          <span class="type-id"> tag #99 </span>
        </div>
        
      </div>

      <div class="detail-section">
        <div class="detail-item detail-item-1 hide">
          detail 1
        </div>
        <div class="detail-item detail-item-2 hide">
          detail 2
        </div>
        <div class="detail-item detail-item-3 hide">
          detail 3
        </div>
      </div>
    </div>

  <div class="tab">
  </div>
  <div class="pagination-container">
    <div class="pagination-arrow left">
      <span class="icon-left-open"></span>
    </div>
    <div class="pagination-item-container">
    
    </div>
    <div class="pagination-arrow rigth">
      <span class="icon-left-open"></span>
    </div>
  </div>
</div>

{if !empty($navbar) }{include file='navigation_bar.tpl'|@get_extent:'navbar'}{/if}

{combine_script id='geoip' load='async'}

{footer_script}
Array.from(document.querySelectorAll(".IP")).forEach(function(ipEl) {
  var handled = false;
  ipEl.addEventListener('mouseenter', function onFirstEnter() {
    if (handled) return;
    handled = true;
    var isOver = true;
    ipEl.removeEventListener('mouseenter', onFirstEnter);
    ipEl.addEventListener('mouseleave', function() { isOver = false; });

    GeoIp.get(ipEl.textContent, function(data) {
      if (!data.fullName) return;

      var content = data.fullName;
      if (data.latitude && data.region_name) {
        content += '<br><a class="ipGeoOpen" data-lat="' + data.latitude + '" data-lon="' + data.longitude + '"';
        content += ' href="#">show on a Google Map</a>';
      }

      ipEl.tipTip({
        content: content,
        keepAlive: true,
        defaultPosition: "right",
        maxWidth: 320,
      });
      if (isOver) ipEl.dispatchEvent(new Event('mouseenter'));
    });
  });
});

document.addEventListener('click', function(e) {
  var target = e.target.closest('.ipGeoOpen');
  if (!target) return;
  e.preventDefault();
  var lat = target.dataset.lat;
  var lon = target.dataset.lon;
  var parent = target.parentElement;
  target.remove();

  var append = '<br><img width=300 height=220 src="http://maps.googleapis.com/maps/api/staticmap';
  append += '?sensor=false&size=300x220&zoom=6&markers=size:tiny%7C' + lat + ',' + lon + '">';

  if (parent) parent.insertAdjacentHTML('beforeend', append);
});
{/footer_script}

{combine_css path="admin/themes/default/css/pages/history.css"}