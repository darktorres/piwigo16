{if !empty($thumbnails)}
  {foreach from=$thumbnails item=thumbnail}
  {assign var=derivative value=$pwg->derivative($GDThumb_derivative_params, $thumbnail.src_image)}
  {assign var=media_type value={media_type file=$thumbnail.file}}
  {assign var=media_type_name value={$media_type|capitalize:false:true}}
  {* {$thumbnails|print_r} *}

<li class="gdthumb{if $GDThumb.thumb_animate} animate{/if} {$GDThumb.method}">
  {if $GDThumb.thumb_mode_photo !== "hide" }
  <span class="thumbLegend {$GDThumb.thumb_mode_photo}">
    <span class="thumbName thumbTitle">
    {if $GDThumb.normalize_title == "desc" && $thumbnail.DESCRIPTION}
      {$thumbnail.DESCRIPTION}
    {elseif $GDThumb.normalize_title == "off"}
      {$thumbnail.NAME}
    {else}
      {assign var="file_title" value=$thumbnail.NAME|cat:"."}
      {assign var="file_name" value=$thumbnail.file|replace:"_":" "}
      {if $file_name|strstr:$file_title}
      {$media_type_name|@translate} {$thumbnail.id}
      {else}
      {$thumbnail.NAME}
      {/if}
    {/if}
    {if $GDThumb.thumb_mode_album !== "overlay-ex"}
      {if !empty($thumbnail.icon_ts)}
      <img title="{$thumbnail.icon_ts.TITLE}" src="{$ROOT_URL}{$themeconf.icon_dir}/recent.png" alt="(!)">
      {/if}
    {/if}
    </span>
    {if $GDThumb.thumb_mode_album == "overlay-ex"}
      <span class="thumbInfo">
        <span class="hit-num">{$thumbnail.hit}</span>
        <span class="fas {if $media_type=="video"}fa-file-video{elseif $media_type=="music"}fa-file-audio{elseif $media_type=="doc"}fa-file-word{elseif $media_type=="pdf"}fa-file-pdf{else}fa-image{/if}"></span>
        {if !empty($thumbnail.icon_ts)}
        <span class="new-thumb fas fa-asterisk" title="{$thumbnail.icon_ts.TITLE}" alt="(!)"></span>
        {/if}
        {if $thumbnail.rating_score > 0}
        <span class="rank-num"><i class="fas fa-star"></i>{$thumbnail.rating_score|string_format:"%d"}</span>
        {/if}
      </span>
    {elseif $GDThumb.thumb_metamode !== "hide"}
      {if isset($thumbnail.NB_COMMENTS)}
      <span class="{if 0==$thumbnail.NB_COMMENTS}zero {/if}nb-comments">{$pwg->l10n_dec('%d comment', '%d comments',$thumbnail.NB_COMMENTS)}</span>
      {/if}
      {if isset($thumbnail.NB_COMMENTS) && isset($thumbnail.NB_HITS)} - {/if}
      {if isset($thumbnail.NB_HITS)}
      <span class="{if 0==$thumbnail.NB_HITS}zero {/if}nb-hits">{$pwg->l10n_dec('%d visit', '%d visits',$thumbnail.NB_HITS)}</span>
      {elseif isset($thumbnail.hit)}
      <span class="{if 0==$thumbnail.hit}zero {/if}nb-hits">{$pwg->l10n_dec('%d visit', '%d visits',$thumbnail.hit)}</span>
      {/if}
      {if isset($thumbnail.rating_score)}
      <span class="{if 0==$thumbnail.rating_score}zero {/if}rating">, {'Rating:'|@translate} {$thumbnail.rating_score}</span>
      {/if}
    {/if}
  </span>
  {/if}
  <a href="{$thumbnail.URL}">
    <img class="thumbnail" {if $derivative->is_cached()}src="{$derivative->get_url()}"{else}src="{$ROOT_URL}{$themeconf.icon_dir}/img_small.png" data-src="{$derivative->get_url()}"{/if} alt="{$thumbnail.TN_ALT}" title="{$thumbnail.TN_TITLE}" {$derivative->get_size_htm()}>
  </a>
</li>
{/foreach}

{combine_css path=$GDThumb.GDTHUMB_ROOT|cat:"/css/gdthumb.css" version=1}
{combine_script id='jquery.ajaxmanager' path='themes/default/js/plugins/jquery.ajaxmanager.js' load='footer'}
{combine_script id='thumbnails.loader' path='themes/default/js/thumbnails.loader.js' require='jquery.ajaxmanager' load='footer'}
{combine_script id='jquery.ba-resize' path=$GDThumb.GDTHUMB_ROOT|cat:"/js/jquery.ba-resize.min.js" load="footer"}
{combine_script id='gdthumb' require='jquery,jquery.ba-resize' path=$GDThumb.GDTHUMB_ROOT|cat:"/js/gdthumb.js" load="footer"}

{footer_script require="gdthumb"}

  {if isset($has_cats)}
  {else}
$(function() {
  {if isset($GDThumb_big)}
  {assign var=gt_size value=$GDThumb_big->get_size()}
  var big_thumb = {ldelim}id: {$GDThumb_big->src_image->id}, src: '{$GDThumb_big->get_url()}', width: {$gt_size[0]}, height: {$gt_size[1]}{rdelim};
  {else}
  var big_thumb = null;
  {/if}
  GDThumb.setup('{$GDThumb.method}', {$GDThumb.height}, {$GDThumb.margin}, false, big_thumb, {$GDThumb.big_thumb_noinpw});
});
  {/if}
{/footer_script}

{html_head}
<style type="text/css">#thumbnails .gdthumb {ldelim} margin:{$GDThumb.margin / 2}px {$GDThumb.margin / 2}px {$GDThumb.margin - $GDThumb.margin / 2}px {$GDThumb.margin - $GDThumb.margin / 2}px !important; }</style>
<!--[if IE 8]>
<style type="text/css">#thumbnails .gdthumb a {ldelim} right: 0px; }</style>
<![endif]-->
{/html_head}
{/if}