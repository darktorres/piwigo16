{combine_script id='rating_user' load='footer' path='admin/themes/default/js/rating_user.js'}
{html_style}
.dtBar {
	text-align:left;
	padding: 10px 0 10px 20px
}
.dtBar DIV{
	display:inline;
	padding-right: 5px;
}
.dataTables_paginate A {
	padding-left: 3px;
}
{/html_style}
{footer_script}
document.querySelector('h1')?.insertAdjacentHTML('beforeend', "<span class='badge-number'>{$NB_ELEMENTS}</span>");
{/footer_script}

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
{combine_script id='core.scripts' load='async' path='themes/default/js/scripts.js'}
{combine_script id='jquery.geoip' load='async'}
{footer_script}
window.oTable = new DataTable('#rateTable', {
	pageLength: 100,
	lengthMenu: [[25, 50, 100, 500, -1], [25, 50, 100, 500, "All"]],
	order: [],
	autoWidth: false,
	columnDefs: [
		{ targets: '.dtc_user', type: 'string' },
		{ targets: '.dtc_date', orderSequence: ['desc', 'asc'], type: 'string' },
		{ targets: '.dtc_stat', orderSequence: ['desc', 'asc'], searchable: false, type: 'num' },
		{ targets: '.dtc_rate', orderSequence: ['desc', 'asc'], searchable: false, type: 'html' },
		{ targets: '.dtc_del', orderable: false, searchable: false }
	]
});

function uidFromCell(cell) {
	var tr = cell;
	while (tr.nodeName !== "TR") tr = tr.parentNode;
	return JSON.parse(tr.getAttribute('data-usr'));
}

document.getElementById('rateTable')?.addEventListener('click', function(e) {
	var delBtn = e.target.closest('.del');
	if (!delBtn) return;
	e.preventDefault();
	var tr = delBtn.closest('tr');
	var usrName = tr.querySelector('.usr')?.innerHTML ?? '';
	var title_msg = '{'Are you sure you want to delete the ratings of the user "%s"?'|@translate|@escape:'javascript'}';
	if (!window.confirm(title_msg.replace('%s', usrName))) return;
	var cell = delBtn.parentElement;
	var data = uidFromCell(cell);
	tr.style.opacity = '0.4';
	(new PwgWS('{$ROOT_URL|@escape:javascript}')).callService(
		'pwg.rates.delete', { user_id: data.uid, anonymous_id: data.aid },
		{
			method: 'POST',
			onFailure: function(num, text) { tr.style.opacity = '1'; alert(num + ' ' + text); },
			onSuccess: function(result) {
				if (result)
					window.oTable.row(tr).remove().draw();
				else
					alert(result);
			}
		}
	);
});
{/footer_script}
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
<tr data-usr='{ldelim}"uid":{$rating.uid},"aid":"{$rating.aid}"}'>
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

