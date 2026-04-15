{if !empty($thumbnails)}
  {foreach $thumbnails as $thumbnail}
    {assign var=derivative value=$pwg->derivative($GDThumb_derivative_params, $thumbnail.src_image)}
    {assign var=deriv_size value=$derivative->get_size()}

    <li class="gdthumb {$GDThumb.method}">
      {if $GDThumb.thumb_mode_photo !== "hide" }
        <span class="thumbLegend {$GDThumb.thumb_mode_photo}">
          <span class="thumbName thumbTitle">
            {if $GDThumb.normalize_title == "desc" && $thumbnail.DESCRIPTION}
              {$thumbnail.DESCRIPTION}
            {elseif $GDThumb.normalize_title == "on"}
              {assign var="file_title" value=$thumbnail.NAME|cat:"."}
              {assign var="file_name" value=$thumbnail.file|replace:"_":" "}
              {if $file_name|strstr:$file_title}
                {$thumbnail.id}
              {else}
                {$thumbnail.NAME}
              {/if}
            {else}
              {$thumbnail.NAME}
            {/if}
            {if $GDThumb.thumb_mode_photo !== "overlay-ex"}
              {if !empty($thumbnail.icon_ts)}
                <img title="{$thumbnail.icon_ts.TITLE}" src="{$ROOT_URL}{$themeconf.icon_dir|default:null}/recent.png" alt="(!)">
              {/if}
            {/if}
          </span>
          {if $GDThumb.thumb_mode_photo == "overlay-ex"}
            <span class="thumbInfo">
              <span class="hit-num">{$thumbnail.hit}</span>
              <span class="fas fa-image"></span>
              {if !empty($thumbnail.icon_ts)}
                <span class="new-thumb fas fa-asterisk" title="{$thumbnail.icon_ts.TITLE}"></span>
              {/if}
              {if $thumbnail.rating_score > 0}
                <span class="rank-num"><i class="fas fa-star"></i>{$thumbnail.rating_score|string_format:"%d"}</span>
              {/if}
            </span>
          {elseif $GDThumb.thumb_metamode !== "hide"}
            {if isset($thumbnail.NB_COMMENTS)}
              <span
                class="{if 0==$thumbnail.NB_COMMENTS}zero {/if}nb-comments">{$thumbnail.NB_COMMENTS|translate_dec:'%d comment':'%d comments'}</span>
            {/if}
            {if isset($thumbnail.NB_COMMENTS) && isset($thumbnail.NB_HITS)} - {/if}
            {if isset($thumbnail.NB_HITS)}
              <span
                class="{if 0==$thumbnail.NB_HITS}zero {/if}nb-hits">{$thumbnail.NB_HITS|translate_dec:'%d visit':'%d visits'}</span>
            {elseif isset($thumbnail.hit)}
              <span
                class="{if 0==$thumbnail.hit}zero {/if}nb-hits">{$thumbnail.hit|translate_dec:'%d visit':'%d visits'}</span>
            {/if}
            {if isset($thumbnail.rating_score)}
              <span class="{if 0==$thumbnail.rating_score}zero {/if}rating">, {'Rating:'|translate}
                {$thumbnail.rating_score}</span>
            {/if}
          {/if}
        </span>
      {/if}
      {assign var=src_size value=$derivative->src_image->get_size()}
      <a href="{$thumbnail.URL}" data-pswp-src="{$derivative->src_image->get_url()}" data-pswp-width="{$src_size.0}"
        data-pswp-height="{$src_size.1}">
        <img class="thumbnail" src="{$derivative->get_url()}" {$derivative->get_size_htm()} loading="lazy" decoding="async"
          alt="{$thumbnail.TN_ALT}" title="{$thumbnail.TN_TITLE}">
      </a>
    </li>
  {/foreach}

  {combine_css path=$GDThumb.GDTHUMB_ROOT|cat:"/css/gdthumb.css" version=1}
  {combine_css path=$GDThumb.GDTHUMB_ROOT|cat:"/js/photoswipe/photoswipe.css"}
  {combine_script id='gdthumb' require='jquery' path=$GDThumb.GDTHUMB_ROOT|cat:"/js/gdthumb.js" load="footer"}
  {combine_script id='gdthumb.masonry' require='gdthumb' path=$GDThumb.GDTHUMB_ROOT|cat:"/js/masonry.js" load="footer"}

  {footer_script require="gdthumb.masonry"}<script>
    {if isset($has_cats)}
    {else}
      $(function() {
        GDThumb.setup('{$GDThumb.method}', {$GDThumb.height}, {$GDThumb.margin}, false);
      });
    {/if}
  </script>{/footer_script}

  {footer_script}<script type="module">
    if (!window._pswpInitialized) {
      window._pswpInitialized = true;
      const { default: PhotoSwipeLightbox } = await import('./themes/bootstrap_darkroom/node_modules/photoswipe/dist/photoswipe-lightbox.esm.js');
      const lightbox = new PhotoSwipeLightbox({
        gallery: '#thumbnails',
        children: 'a[data-pswp-src]',
        pswpModule: () => import('./themes/bootstrap_darkroom/node_modules/photoswipe/dist/photoswipe.esm.js')
      });
      lightbox.init();
    }
  </script>{/footer_script}
{/if}
