{if !empty($thumbnails)}
	{*define_derivative name='derivative_params' width=160 height=90 crop=true*}
	{html_style}<style>
		{*Set some sizes according to maximum thumbnail width and height*}
		.thumbnails SPAN,
		.thumbnails .wrap2 A,
		.thumbnails LABEL {
			width: {$derivative_params->max_width()+2}px;
		}

		.thumbnails .wrap2 {
			height: {$derivative_params->max_height()+3}px;
		}

		{if $derivative_params->max_width() > 600}
			.thumbLegend {
				font-size: 130%
			}

		{else}
			{if $derivative_params->max_width() > 400}
				.thumbLegend {
					font-size: 110%
				}

			{else}
				.thumbLegend {
					font-size: 90%
				}

			{/if}
		{/if}
	</style>{/html_style}
	{foreach $thumbnails as $thumbnail}
		{assign var=derivative value=$pwg->derivative($derivative_params, $thumbnail.src_image)}
		<li>
			<span class="wrap1">
				<span class="wrap2">
					<a href="{$thumbnail.URL}">
						<img class="thumbnail" src="{$derivative->get_url()}" {$derivative->get_size_htm()} loading="lazy"
							decoding="async" alt="{$thumbnail.TN_ALT}" title="{$thumbnail.TN_TITLE}">
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
	{/foreach}
{/if}