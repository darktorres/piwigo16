<ul>
	<li><a href="#menubar" data-rel="close">{'Close'|translate}</a></li>
</ul>
{if !empty($blocks) }
	<div>
		{foreach from=$blocks key=id item=block}
			<div class="sp-collapsible">
				{if not empty($block->template)}
					{include file=$block->template assign=the_block|get_extent:$id}
					{$the_block|replace:'dt':'h3'|replace:'<dd>':''|replace:'</dd>':''}
				{else}
					{$block->raw_content|replace:'dt':'h3'|replace:'<dd>':''|replace:'</dd>':''}
				{/if}
			</div>
		{/foreach}
	</div>
{/if}
<ul>
	<li class="sp-divider">{'View in'|translate}</li>
	{if isset($TOGGLE_MOBILE_THEME_URL)}
		<li><a href="{$TOGGLE_MOBILE_THEME_URL}">{'Desktop'|translate}</a></li>
	{/if}
</ul>