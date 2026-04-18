{if preg_match("/(mp4|m4v|webm|ogv|mov|mkv)$/i", $current.path)}
  {if $current.height < $current.width}
    <div id="video-modal" class="col-lg-8 col-md-10 col-sm-12 mx-auto">
      {if $current.height / $current.width * 100 < 60}
        <div class="ratio ratio-16x9">
        {else}
          <div class="ratio embed-responsive-custom"
            style="padding-bottom:{$current.height / $current.width * 100}%">
          {/if}
        {else}
          <div id="video-modal" class="col-lg-3 col-md-5 col-sm-6 col-xs-8 mx-auto">
            <div class="ratio embed-responsive-9by16">
            {/if}
            <video id="video" class="" width="100%" height="auto" controls autoplay loop preload="auto"
              poster="{$current.selected_derivative->get_url()}">
              <source src="{$ROOT_URL}{$current.path}" type="{if $current.path_ext == 'webm'}video/webm{elseif $current.path_ext == 'ogv'}video/ogg{else}video/mp4{/if}">
              </source>
            </video>
          </div>
        </div>
      {else}
        <img
          class="{if isset($current.path_ext)}path-ext-{$current.path_ext}{/if} {if isset($current.file_ext)}file-ext-{$current.file_ext}{/if}"
          src="{$current.selected_derivative->get_url()}" {$current.selected_derivative->get_size_htm()} loading="lazy"
          decoding="async" alt="{$ALT_IMG}" id="theMainImage" usemap="#map{$current.selected_derivative->get_type()}"
          title="{if isset($COMMENT_IMG)}{$COMMENT_IMG|strip_tags:false|replace:'"':' '}{else}{$current.TITLE_ESC} - {$ALT_IMG}{/if}">

      {foreach $current.unique_derivatives as $derivative_type => $derivative}
      <map name="map{$derivative->get_type()}">
        {assign var='size' value=$derivative->get_size()}
        {if isset($previous)}
        <area shape=rect coords="0,0,{($size[0]/4)|intval},{$size[1]}" href="{$previous.U_IMG}"
          title="{'Previous'|translate} : {$previous.TITLE_ESC}" alt="{$previous.TITLE_ESC}">
        {/if}
        <area shape=rect coords="{($size[0]/4)|intval},0,{($size[0]/1.34)|intval},{($size[1]/4)|intval}"
          href="{$U_UP}" title="{'Thumbnails'|translate}" alt="{'Thumbnails'|translate}">
        {if isset($next)}
        <area shape=rect coords="{($size[0]/1.33)|intval},0,{$size[0]},{$size[1]}" href="{$next.U_IMG}"
          title="{'Next'|translate} : {$next.TITLE_ESC}" alt="{$next.TITLE_ESC}">
        {/if}
      </map>
      {/foreach}
{/if}