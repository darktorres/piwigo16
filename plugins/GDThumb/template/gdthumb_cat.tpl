<div class="loader"><img src="{$ROOT_URL}{$themeconf.img_dir|default:null}/ajax_loader.gif" alt=""></div>
<ul class="thumbnailCategories thumbnails {if $gdthumb_config.no_wordwrap}nowrap{/if}">

  {if !empty($category_thumbnails)}
    {assign var=has_cats value="true" scope=root nocache}
    {foreach $category_thumbnails as $cat}
      {assign var=derivative value=$pwg->derivative($gdthumb_derivatives, $cat.representative.src_image)}

      <li class="gdthumb{if $gdthumb_config.thumb_animate} animate{/if} {$gdthumb_config.method} album">
        {if $gdthumb_config.thumb_mode_album !== "hide" }
          <span class="thumbLegend {$gdthumb_config.thumb_mode_album}">
            <span class="thumbName">
              <span class="thumbTitle">{$cat.NAME}
                {if $gdthumb_config.thumb_mode_album !== "overlay-ex"}
                  {if !empty($cat.icon_ts)}
                    <img title="{$cat.icon_ts.TITLE}"
                      src="{$ROOT_URL}{$themeconf.icon_dir|default:null}/recent{if $cat.icon_ts.IS_CHILD_DATE}_by_child{/if}.png" alt="(!)">
                  {/if}
                {/if}
              </span>
              {if $gdthumb_config.thumb_mode_album == "overlay-ex"}
                <span class="thumbInfo">
                  <span class="item-num">{$cat.count_images}</span>
                  <span class="fas fa-th-large grid-gallery-icon"></span>
                  {if !empty($cat.icon_ts)}
                    <span class="new-thumb fas fa-asterisk" title="{$cat.icon_ts.TITLE}" alt="(!)"></span>
                  {/if}
                </span>
              {elseif $gdthumb_config.thumb_metamode !== "hide"}
                {if isset($cat.INFO_DATES) }
                  <span class="dates">{$cat.INFO_DATES}</span>
                {/if}
                <span
                  class="Nb_images">{if $gdthumb_config.no_wordwrap}{$cat.CAPTION_NB_IMAGES|regex_replace:"[<br>|</br>]":" "}{else}{$cat.CAPTION_NB_IMAGES}{/if}</span>
                {if $gdthumb_config.thumb_metamode == "merged_desc"}
                  {if not empty($cat.DESCRIPTION)}
                    <span class="description">{$cat.DESCRIPTION}</span>
                  {/if}
                {/if}
              {/if}
            </span>
          </span>
        {/if}
        <a href="{$cat.URL}">
          <img class="category thumbnail" src="{$derivative->get_url()}" {$derivative->get_size_htm()} loading="lazy"
            decoding="async" alt="{$cat.TN_ALT}" title="{$cat.NAME|replace:'\"':' '|strip_tags:false}"
            {$derivative->get_size_htm()}>
        </a>
      </li>
    {/foreach}
  {/if}

</ul>

{html_style}<style>
  .thumbnailCategories .gdthumb { margin: {$gdthumb_config.margin / 2}px {$gdthumb_config.margin / 2}px {$gdthumb_config.margin - $gdthumb_config.margin / 2}px {$gdthumb_config.margin - $gdthumb_config.margin / 2}px !important; }
</style>{/html_style}

{combine_css path=$gdthumb_config.GDTHUMB_ROOT|cat:"/css/gdthumb.css"}
{combine_script id='gdthumb' require='jquery' path=$gdthumb_config.GDTHUMB_ROOT|cat:"/js/gdthumb.js" load="footer"}

{footer_script require="gdthumb"}<script>
  $(function() {
    {if isset($gdthumb_big_thumb)}
      {assign var=gt_size value=$gdthumb_big_thumb->get_size()}
      var big_thumb = { id: {$gdthumb_big_thumb->src_image->id}, src: '{$gdthumb_big_thumb->get_url()}', width: {$gt_size[0]}, height: {$gt_size[1]} };
    {else}
      var big_thumb = null;
    {/if}
    GDThumb.setup('{$gdthumb_config.method}', {$gdthumb_config.height}, {$gdthumb_config.margin}, true, big_thumb, {$gdthumb_config.big_thumb_noinpw});
  });
</script>{/footer_script}