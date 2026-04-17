<div align="center">
	{if isset($previous)}
		<a href="{$previous.U_IMG}" rel="prev">{'Previous'|translate}</a>
	{/if}
	{if isset($U_UP) and !isset($slideshow)}
		<a href="{$U_UP}" rel="prev">data-iconpos="notext"</a>
	{/if}
	{if isset($next)}
		<a href="{$next.U_IMG}" rel="next">{'Next'|translate}</a>
	{/if}
</div>