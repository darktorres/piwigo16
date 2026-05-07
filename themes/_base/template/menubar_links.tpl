<dt>{'Links'|@translate}</dt>
<dd>
	<ul>{strip}
		{foreach from=$block->data item=link}
			<li>
				<a href="{$link.URL}" class="external"{if isset($link.new_window)} data-window-open-name="{$link.new_window.NAME|@escape:'html'}" data-window-open-features="{$link.new_window.FEATURES|@escape:'html'}"{/if}>
				{$link.LABEL}
				</a>
			</li>
		{/foreach}
	{/strip}</ul>
</dd>
