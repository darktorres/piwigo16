{if isset($current.path_ext) and ($current.path_ext == 'mp4' or $current.path_ext == 'm4v' or $current.path_ext == 'webm' or $current.path_ext == 'ogv' or $current.path_ext == 'mov' or $current.path_ext == 'mkv')}
<video width="100%" height="auto" controls preload="auto"
  poster="{$current.selected_derivative->get_url()}">
  <source src="{$ROOT_URL}{$current.path}" type="{if $current.path_ext == 'webm'}video/webm{elseif $current.path_ext == 'ogv'}video/ogg{else}video/mp4{/if}">
</video>
{else}
<img class="file-ext-{if isset($current.file_ext)}{$current.file_ext}{/if} path-ext-{if isset($current.path_ext)}{$current.path_ext}{/if}"
    {if (isset($current.path_ext) and $current.path_ext == 'svg')} src="{$current.path}"
    {else}src="{$current.selected_derivative->get_url()}" {$current.selected_derivative->get_size_htm()}
    {/if}
    alt="{$ALT_IMG}" id="theMainImage" usemap="#map{$current.selected_derivative->get_type()}"
    title="{if isset($COMMENT_IMG)}{$COMMENT_IMG|strip_tags:false|replace:'"':' '}{else}{$current.TITLE_ESC} - {$ALT_IMG}{/if}">

{foreach $current.unique_derivatives as $derivative_type => $derivative}
<map name="map{$derivative->get_type()}">
    {assign var='size' value=$derivative->get_size()}
    {if isset($previous)}
    <area shape=rect coords="0,0,{($size[0]/4)|intval},{$size[1]}" href="{$previous.U_IMG}"
        title="{'Previous'|translate} : {$previous.TITLE_ESC}" alt="{$previous.TITLE_ESC}">
    {/if}
    <area shape=rect coords="{($size[0]/4)|intval},0,{($size[0]/1.34)|intval},{($size[1]/4)|intval}" href="{$U_UP}"
        title="{'Thumbnails'|translate}" alt="{'Thumbnails'|translate}">
    {if isset($next)}
    <area shape=rect coords="{($size[0]/1.33)|intval},0,{$size[0]},{$size[1]}" href="{$next.U_IMG}"
        title="{'Next'|translate} : {$next.TITLE_ESC}" alt="{$next.TITLE_ESC}">
    {/if}
</map>
{/foreach}
{/if}