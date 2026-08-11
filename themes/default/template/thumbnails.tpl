{if !empty($thumbnails)}{strip}
{*define_derivative name='derivative_params' width=160 height=90 crop=true*}
{html_style}
{*Set some sizes according to maximum thumbnail width and height*}
.thumbnails SPAN,
.thumbnails .wrap2 A,
.thumbnails LABEL{ldelim}
	width: {$derivative_params->maxWidth()+2}px;
}

.thumbnails .wrap2{ldelim}
	height: {$derivative_params->maxHeight()+3}px;
}
{if $derivative_params->maxWidth() > 600}
.thumbLegend {ldelim}font-size: 130%}
{else}
{if $derivative_params->maxWidth() > 400}
.thumbLegend {ldelim}font-size: 110%}
{else}
.thumbLegend {ldelim}font-size: 90%}
{/if}
{/if}
{/html_style}
{footer_script}
  var error_icon = "{$ROOT_URL}{$themeconf.icon_dir}/errors_small.png", max_requests = {$maxRequests};
{/footer_script}
{foreach from=$thumbnails item=thumbnail}
{assign var=derivative value=$pwg->derivative($derivative_params, $thumbnail.src_image)}
{if !$derivative->isCached()}
{combine_script id='jquery.ajaxmanager' path='themes/default/js/plugins/jquery.ajaxmanager.js' load='footer'}
{combine_script id='thumbnails.loader' path='themes/default/js/thumbnails.loader.js' require='jquery.ajaxmanager' load='footer'}
{/if}
<li>
	<span class="wrap1">
		<span class="wrap2">
		<a href="{$thumbnail.URL}">
			<img class="thumbnail" {if $derivative->isCached()}src="{$derivative->getUrl()}"{else}src="{$ROOT_URL}{$themeconf.icon_dir}/img_small.png" data-src="{$derivative->getUrl()}"{/if} alt="{$thumbnail.TN_ALT}" title="{$thumbnail.TN_TITLE}">
		</a>
		</span>
		{if $SHOW_THUMBNAIL_CAPTION }
		<span class="thumbLegend">
		<span class="thumbName">{$thumbnail.NAME}</span>
		{if !empty($thumbnail.icon_ts)}
		<img title="{$thumbnail.icon_ts.TITLE}" src="{$ROOT_URL}{$themeconf.icon_dir}/recent.png" alt="(!)">
		{/if}
		{if isset($thumbnail.NB_COMMENTS)}
		<span class="{if 0==$thumbnail.NB_COMMENTS}zero {/if}nb-comments">
		<br>
		{$thumbnail.NB_COMMENTS|translate_dec:'%d comment':'%d comments'}
		</span>
		{/if}

		{if isset($thumbnail.NB_HITS)}
		<span class="{if 0==$thumbnail.NB_HITS}zero {/if}nb-hits">
		<br>
		{$thumbnail.NB_HITS|translate_dec:'%d hit':'%d hits'}
		</span>
		{/if}
		</span>
		{/if}
	</span>
	</li>
{/foreach}{/strip}
{/if}
