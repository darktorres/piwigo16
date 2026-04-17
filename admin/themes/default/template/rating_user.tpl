{combine_script id='dataTables' load='footer' path='node_modules/datatables.net/js/dataTables.js'}
{combine_css path='node_modules/datatables.net-dt/css/dataTables.dataTables.css'}
{html_style}<style>

	.dtBar {
		text-align: left;
		padding: 10px 0 10px 20px
	}

	.dtBar DIV {
		display: inline;
		padding-right: 5px;
	}

	.dataTables_paginate A {
		padding-left: 3px;
	}

	.ui-tooltip {
		padding: 8px;
		position: absolute;
		z-index: 9999;
		max-width: {3*$TN_WIDTH}px;
		-webkit-box-shadow: 0 0 5px #aaa;
		box-shadow: 0 0 5px #aaa;
	}

	body .ui-tooltip {
		border-width: 2px;
	}
</style>{/html_style}
{footer_script}<script>
	document.addEventListener('DOMContentLoaded', function() {
		var h1 = document.querySelector('h1');
		if (h1) h1.insertAdjacentHTML('beforeend', "<span class='badge-number'>{$NB_ELEMENTS}</span>");
	});
</script>{/footer_script}

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
{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_script id='pwgConfirm' load='footer' path='admin/themes/default/js/pwgConfirm.js'}
{combine_script id='core.scripts' load='async' path='themes/default/js/scripts.js'}
{combine_script id='jquery.geoip' load='async' path='admin/themes/default/js/jquery.geoip.js'}
{footer_script}<script>
	var oTable = new DataTable('#rateTable', {
		dom: '<"dtBar"filp>rt<"dtBar"ilp>',
		pageLength: 100,
		lengthMenu: [
			[25, 50, 100, 500, -1],
			[25, 50, 100, 500, "All"]
		],
		order: [],
		autoWidth: false,
		columnDefs: [{
				targets: ["dtc_user"],
				type: "string"
			},
			{
				targets: ["dtc_date"],
				orderSequence: ["desc", "asc"],
				type: "string"
			},
			{
				targets: ["dtc_stat"],
				orderSequence: ["desc", "asc"],
				searchable: false,
				type: "numeric"
			},
			{
				targets: ["dtc_rate"],
				orderSequence: ["desc", "asc"],
				searchable: false,
				type: "html"
			},
			{
				targets: ["dtc_del"],
				orderable: false,
				searchable: false,
				type: "string"
			}
		]
	});

	function uidFromCell(cell) {
		var tr = cell;
		while (tr.nodeName != "TR") tr = tr.parentNode;
		return JSON.parse(tr.dataset.usr);
	}

	{* -----DELETE----- *}
	document.addEventListener('DOMContentLoaded', function() {
		var rateTable = document.getElementById("rateTable");
		if (rateTable) {
			rateTable.addEventListener("click", function(e) {
				var delBtn = e.target.closest(".del");
				if (!delBtn) return;
				e.preventDefault();
				const title_msg  = '{'Are you sure you want to delete the ratings of the user "%s"?'|translate|escape:'javascript'}';
				const confirm_msg = '{"Yes, I am sure"|translate}';
				const cancel_msg = "{"No, I have changed my mind"|translate}";
				let trEl = delBtn.closest("tr");
				let usr_name = trEl ? (trEl.querySelector(".usr")?.innerHTML ?? '') : '';
				pwgConfirm({
						title: title_msg.replace("%s", usr_name),
						buttons: {
							confirm: {
								text: confirm_msg,
								btnClass: 'btn-red',
								action: function() {
									var cell = e.target.parentNode,
										trEl = cell;
									while (trEl.nodeName != "TR") trEl = trEl.parentNode;
									var anim = trEl.animate([{ opacity: 1 }, { opacity: 0.4 }], { duration: 1000, fill: 'forwards' });
									var data = uidFromCell(cell);
									(new PwgWS('{$ROOT_URL|escape:javascript}')).callService(
										'pwg.rates.delete', {
											user_id: data.uid,
											anonymous_id: data.aid
										}, {
											method: 'POST',
											onFailure: function(num, text) {
												anim.cancel();
												trEl.style.opacity = '1';
												alert(num + " " + text);
											},
											onSuccess: function(result) {
												if (result)
													oTable.row(trEl).remove().draw();
												else
													alert(result);
											}
										}
									);
								}
							},
							cancel: {
								text: cancel_msg
							}
						}
					});
			});
		}
	});
</script>{/footer_script}
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
