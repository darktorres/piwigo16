<dt>{'Menu'|translate}</dt>
<dd>
{if isset($block->data.qsearch) and  $block->data.qsearch==true}
	<form action="{$ROOT_URL}qsearch.php" method="get" id="quicksearch">
		<p class="u-reset-margin"{*this <p> is for html validation only - does not affect positioning*}>
			<input type="text" name="q" id="qsearchInput" placeholder="{'Quick search'|translate|@escape:'html'}" required class="u-w-90p"{if !empty($QUERY_SEARCH)} value="{$QUERY_SEARCH}"{/if}>
		</p>
	</form>
{/if}
	<ul>{strip}
	{foreach from=$block->data item=link}
		{if is_array($link)}
			<li><a href="{$link.URL}"{if isset($link.TITLE)} title="{$link.TITLE}"{/if}{if isset($link.REL)} {$link.REL}{/if}>{$link.NAME}</a>{if isset($link.COUNTER)} ({$link.COUNTER}){/if}</li>
		{/if}
	{/foreach}
	{/strip}</ul>
</dd>
