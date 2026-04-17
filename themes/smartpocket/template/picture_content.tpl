{if isset($current.path_ext) and ($current.path_ext == 'mp4' or $current.path_ext == 'm4v' or $current.path_ext == 'webm' or $current.path_ext == 'ogv' or $current.path_ext == 'mov' or $current.path_ext == 'mkv')}
<video width="100%" height="auto" controls preload="auto"
  poster="{$current.selected_derivative->get_url()}">
  <source src="{$ROOT_URL}{$current.path}" type="{if $current.path_ext == 'webm'}video/webm{elseif $current.path_ext == 'ogv'}video/ogg{else}video/mp4{/if}">
</video>
{else}
<img src="{$current.selected_derivative->get_url()}" style="max-width:100%" alt="{$ALT_IMG}" id="theMainImage"
title="{if isset($COMMENT_IMG)}{$COMMENT_IMG|strip_tags:false|replace:'"':' '}{else}{$current.TITLE|replace:'"':' '} - {$ALT_IMG}{/if}">
{/if}