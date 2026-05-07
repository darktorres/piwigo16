{combine_script id='common' load='footer' path='themes/admin/default/js/common.js'}
{combine_script id='rating_admin' load='footer' require='common' path='themes/admin/default/js/rating_admin.js'}

{combine_css path="themes/admin/default/css/pages/rating.css"}

<script id="pwg-rating-data" type="application/json">{$rating_page_data_json}</script>

<form action="{$F_ACTION}" method="GET" class="filter">
  <fieldset>
    <legend><span class="icon-filter icon-green"></span>{'Filter'|@translate}</legend>

    <label>
      {'Sort by'|@translate}
      <select name="order_by">
        {html_options options=$order_by_options selected=$order_by_options_selected}
      </select>
    </label>

    <label>
      {'Users'|@translate}
      <select name="users">
        {html_options options=$user_options selected=$user_options_selected}
      </select>
    </label>

    <label>
      {'Number of items'|@translate}
      <input type="text" name="display" size="2" value="{$DISPLAY}">
    </label>

    <label>
      {'Album'|translate}<a href="#" id="removeAlbumFilter" class="icon-cancel-circled"></a>
      <select
        data-selectize="categories"
        data-value="{$category|@json_encode|escape:html}"
        placeholder="{'No filter on album. Select one or type to search'|translate}"
        name="cat"
        class="rating-album-filter-select"
      ></select>
    </label>

    <div class="u-clear-both"></div>

    <p class="rating-submit-row">
      <button name="submit" type="submit" class="buttonLike">
        <i class="icon-filter"></i> {'Submit'|translate}
      </button>
    </p>
    <input type="hidden" name="page" value="rating">
  </fieldset>
</form>

{if !empty($navbar) }{include file='navigation_bar.tpl'|@get_extent:'navbar'}{/if}

<table class="rating-table">
<tr class="throw">
  <td>{'File'|@translate}</td>
  <td>{'Number of rates'|@translate}</td>
	<td>{'Rating score'|@translate}</td>
  <td>{'Average rate'|@translate}</td>
  <td>{'Sum of rates'|@translate}</td>
  <td>{'Rate'|@translate}/{'Username'|@translate}/{'Rate date'|@translate}</td>
  <td></td>
</tr>
{foreach from=$images item=image name=image}
<tr valign="top" class="{if $smarty.foreach.image.index is odd}row1{else}row2{/if}">
	<td><a href="{$image.U_URL}"><img src="{$image.U_THUMB}" alt="{$image.FILE}" title="{$image.FILE}"></a></td>
	<td><strong>{$image.NB_RATES}/{$image.NB_RATES_TOTAL}</strong></td>
	<td><strong>{$image.SCORE_RATE}</strong></td>
	<td><strong>{$image.AVG_RATE}</strong></td>
	<td class="sum-rate-cell"><strong>{$image.SUM_RATE}</strong></td>
	<td>
		<table class="rating-rates-table">
{foreach from=$image.rates item=rate name=rate}
<tr>
	<td>{$rate.rate}</td>
	<td><b>{$rate.USER}</b></td>
	<td>{$rate.date}</td>
	<td><a class="icon-trash rating-delete" data-image-id="{$image.id}" data-user-id="{$rate.user_id}"{if !empty($rate.anonymous_id)} data-anonymous-id="{$rate.anonymous_id}"{/if}> </a></td>
</tr>
{/foreach}{*rates*}
		</table>
	</td>
</tr>
{/foreach}{*images*}
</table>
{combine_script id='core.scripts' load='async' path='themes/default/js/scripts.js'}

{if !empty($navbar)}{include file='navigation_bar.tpl'|@get_extent:'navbar'}{/if}
