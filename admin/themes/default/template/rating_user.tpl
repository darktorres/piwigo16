{combine_css path='admin/themes/default/css/pages/rating_user.css' order=-10}

{combine_css path='node_modules/datatables.net-dt/css/dataTables.dataTables.css'}
{html_style}{/html_style}
<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

<form action="{$F_ACTION}" method="GET">
	<fieldset>
		<noscript>
			<label>{'Sort by'|translate}
				<select name="order_by">
					{html_options options=$order_by_options selected=$order_by_options_selected}
				</select>
			</label>
		</noscript>
		<label>{'Number of rates'|translate}&gt;
			<input type="text" size="5" name="f_min_rates" value="{$F_MIN_RATES}">
		</label>
		<label>{'Consensus deviation'|translate}
			<input type="text" size="5" name="consensus_top_number" value="{$CONSENSUS_TOP_NUMBER}">
			{'Best rated'|translate}

		</label>

		<div style="clear:both"></div>

		<p style="margin:10px 0 0 0">
			<button name="submit" type="submit" class="buttonLike">
				<i class="icon-filter"></i> {'Submit'|translate}
			</button>
		</p>
		<input type="hidden" name="page" value="rating_user">
	</fieldset>
</form>
{if $vite_rating_user}
<script type="module" src="admin/themes/default/js/dist/{$vite_rating_user}"></script>
{/if}
<table id="rateTable">
	<thead>
		<tr class="throw">
			<th class="dtc_user">{'Username'|translate}</th>
			<th class="dtc_date">{'Last'|translate}</th>
			<th class="dtc_stat">{'Number of rates'|translate}</th>
			<th class="dtc_stat">{'Average rate'|translate}</th>
			<th class="dtc_stat">{'Variation'|translate}</th>
			<th class="dtc_stat">{'Consensus deviation'|translate|replace:' ':'<br>'}</th>
			<th class="dtc_stat">{'Consensus deviation'|translate|replace:' ':'<br>'} {$CONSENSUS_TOP_NUMBER}</th>
			{foreach $available_rates as $rate}
				<th class="dtc_rate">{$rate}</th>
			{/foreach}
			<th class="dtc_del"></th>
		</tr>
	</thead>
	{foreach $ratings as $user => $rating}
		<tr data-usr='{ "uid":{$rating.uid},"aid":"{$rating.aid}" }'>
			<td class=usr>{$user}</td>
			<td title="First: {$rating.first_date}">{$rating.last_date}</td>
			<td>{$rating.count}</td>
			<td>{$rating.avg|number_format:2}</td>
			<td>{$rating.cv|number_format:3}</td>
			<td>{$rating.cd|number_format:3}</td>
			<td>{if !empty($rating.cdtop)}{$rating.cdtop|number_format:3}{/if}</td>
			{foreach $rating.rates as $rate => $rates}
				<td>{if !empty($rates)}
						{capture assign=rate_over}
							{foreach $rates as $rate_arr}
								{if $rate_arr@index>29}
									{break}
								{/if}<img src="{$image_urls[$rate_arr.id].tn}" alt="thumb-{$rate_arr.id}" width="{$TN_WIDTH}"
								height="{$TN_WIDTH}">{/foreach}
						{/capture}
						<a title="{$rate_over|htmlspecialchars}">{$rates|count}</a>
					{/if}
				</td>
			{/foreach}
			<td><a class="del icon-trash"></a></td>
		</tr>
	{/foreach}
</table>
