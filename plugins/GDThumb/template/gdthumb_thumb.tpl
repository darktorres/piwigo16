{if !empty($thumbnails)}
  {foreach $thumbnails as $thumbnail}
    {assign var=derivative value=$pwg->derivative($gdthumb_derivatives, $thumbnail.src_image)}
    {assign var=media_type value={media_type file=$thumbnail.file}}
    {assign var=media_type_name value={$media_type|capitalize:false:true}}
    {* {$thumbnails|print_r} *}

    <li class="gdthumb{if $gdthumb_config.thumb_animate} animate{/if} {$gdthumb_config.method}">
      {if $gdthumb_config.thumb_mode_photo !== "hide" }
        <span class="thumbLegend {$gdthumb_config.thumb_mode_photo}">
          <span class="thumbName thumbTitle">
            {if $gdthumb_config.normalize_title == "desc" && $thumbnail.DESCRIPTION}
              {$thumbnail.DESCRIPTION}
            {elseif $gdthumb_config.normalize_title == "off"}
              {$thumbnail.NAME}
            {else}
              {assign var="file_title" value=$thumbnail.NAME|cat:"."}
              {assign var="file_name" value=$thumbnail.file|replace:"_":" "}
              {if $file_name|strstr:$file_title}
                {$media_type_name|translate} {$thumbnail.id}
              {else}
                {$thumbnail.NAME}
              {/if}
            {/if}
            {if $gdthumb_config.thumb_mode_album !== "overlay-ex"}
              {if !empty($thumbnail.icon_ts)}
                <img title="{$thumbnail.icon_ts.TITLE}" src="{$ROOT_URL}{$themeconf.icon_dir|default:null}/recent.png" alt="(!)">
              {/if}
            {/if}
          </span>
          {if $gdthumb_config.thumb_mode_album == "overlay-ex"}
            <span class="thumbInfo">
              <span class="hit-num">{$thumbnail.hit}</span>
              <span
                class="fas {if $media_type=="video"}fa-file-video{elseif $media_type=="music"}fa-file-audio{elseif $media_type=="doc"}fa-file-word{elseif $media_type=="pdf"}fa-file-pdf{else}fa-image{/if}"></span>
              {if !empty($thumbnail.icon_ts)}
                <span class="new-thumb fas fa-asterisk" title="{$thumbnail.icon_ts.TITLE}" alt="(!)"></span>
              {/if}
              {if $thumbnail.rating_score > 0}
                <span class="rank-num"><i class="fas fa-star"></i>{$thumbnail.rating_score|string_format:"%d"}</span>
              {/if}
            </span>
          {elseif $gdthumb_config.thumb_metamode !== "hide"}
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
          alt="{$thumbnail.TN_ALT}" title="{$thumbnail.TN_TITLE}" {$derivative->get_size_htm()}>
      </a>
    </li>
  {/foreach}

  {combine_css path=$gdthumb_config.GDTHUMB_ROOT|cat:"/css/gdthumb.css" version=1}
  {combine_script id='gdthumb' path=$gdthumb_config.GDTHUMB_ROOT|cat:"/js/gdthumb.js" load="footer"}

  {footer_script require="gdthumb"}<script>
    {if isset($has_cats)}
    {else}
      {if isset($gdthumb_big_thumb)}
        {assign var=gt_size value=$gdthumb_big_thumb->get_size()}
        var big_thumb = { id: {$gdthumb_big_thumb->src_image->id}, src: '{$gdthumb_big_thumb->get_url()}', width: {$gt_size[0]}, height: {$gt_size[1]} };
      {else}
        var big_thumb = null;
      {/if}
      GDThumb.setup('{$gdthumb_config.method}', {$gdthumb_config.height}, {$gdthumb_config.margin}, false, big_thumb, {$gdthumb_config.big_thumb_noinpw});
    {/if}
  </script>{/footer_script}

  {html_head}
  <style>
    #thumbnails .gdthumb { margin:{$gdthumb_config.margin / 2}px {$gdthumb_config.margin / 2}px {$gdthumb_config.margin - $gdthumb_config.margin / 2}px {$gdthumb_config.margin - $gdthumb_config.margin / 2}px !important; }
  </style>
  {/html_head}
{/if}