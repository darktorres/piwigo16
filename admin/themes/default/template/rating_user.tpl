<script id="pwg-page-data" type="application/json">{$page_data_json}</script>
{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_script id='rating_user' load='footer' require='common' path='admin/themes/default/js/rating_user.js'}

{combine_css path="admin/themes/default/css/pages/rating_user.css"}

<form action="{$F_ACTION}" method="GET">
<fieldset>
<noscript>
	<label>{'Sort by'|@translate}
		<select name="order_by">
			{html_options options=$order_by_options selected=$order_by_options_selected}
		</select>
	</label>
</noscript>
	<label>{'Number of rates'|@translate}&gt;
	<input type="text" size="5" name="f_min_rates" value="{$F_MIN_RATES}">
	</label>
	<label>{'Consensus deviation'|@translate}
	<input type="text" size="5" name="consensus_top_number" value="{$CONSENSUS_TOP_NUMBER}">
	{'Best rated'|@translate}

	</label>

  <div class="u-clear-both"></div>

  <p class="rating-user-submit-row">
    <button name="submit" type="submit" class="buttonLike">
      <i class="icon-filter"></i> {'Submit'|translate}
    </button>
  </p>
	<input type="hidden" name="page" value="rating_user">
</fieldset>
</form>
{combine_script id='core.scripts' load='async' path='themes/default/js/scripts.js'}
{combine_script id='geoip' load='async'}
<table id="rateTable">
<thead>
<tr class="throw">
	<th class="dtc_user">{'Username'|@translate}</th>
	<th class="dtc_date">{'Last'|@translate}</th>
	<th class="dtc_stat">{'Number of rates'|@translate}</th>
	<th class="dtc_stat">{'Average rate'|@translate}</th>
	<th class="dtc_stat">{'Variation'|@translate}</th>
	<th class="dtc_stat">{'Consensus deviation'|@translate|@replace:' ':'<br>'}</th>
	<th class="dtc_stat">{'Consensus deviation'|@translate|@replace:' ':'<br>'} {$CONSENSUS_TOP_NUMBER}</th>
{foreach from=$available_rates item=rate}
	<th class="dtc_rate">{$rate}</th>
{/foreach}
	<th class="dtc_del"></th>
</tr>
</thead>
{foreach from=$ratings item=rating key=user}
<tr data-usr='{ "uid":{$rating.uid},"aid":"{$rating.aid}"}'>
{strip}
<td class=usr>{$user}</td><td title="First: {$rating.first_date}">{$rating.last_date}</td>
<td>{$rating.count}</td><td>{$rating.avg|@number_format:2}</td>
<td>{$rating.cv|@number_format:3}</td><td>{$rating.cd|@number_format:3}</td><td>{if !empty($rating.cdtop)}{$rating.cdtop|@number_format:3}{/if}</td>
{foreach from=$rating.rates item=rates key=rate}
<td>{if !empty($rates)}
{capture assign=rate_over}{foreach $rates as $rate_arr}{if $rate_arr@index>29}{break}{/if}<img src="{$image_urls[$rate_arr.id].tn}" alt="thumb-{$rate_arr.id}" width="{$TN_WIDTH}" height="{$TN_WIDTH}">{/foreach}{/capture}
<a title="{$rate_over|@htmlspecialchars}">{$rates|@count}</a>
{/if}</td>
{/foreach}
<td><a class="del icon-trash"></a></td>
</tr>
{/strip}
{/foreach}
</table>

