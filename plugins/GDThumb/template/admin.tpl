<div class="titrePage">
  <h2>GDThumb - {$GDTHUMB_VERSION}</h2>
  <div class="left-links{if $CUSTOM_CSS!=="yes"} no-gd{/if}">
    <ul><li><a href="http://blog.dragonsoft.us/piwigo/" target="_blank">{'Home'|@translate}</a>&nbsp;|&nbsp;</li>
      {if $CUSTOM_CSS=="yes"}
      <li><a class="ajax cboxElement" href="{$GDTHUMB_PATH|cat:"/changelog.php"}?version={$GDTHUMB_VERSION}">{'Changelog'|@translate}</a>&nbsp;|&nbsp;</li>
      {/if}
      <li><a href="http://piwigo.org/forum/viewtopic.php?id=24413" target="_blank">{'Support'|@translate}</a>&nbsp;|&nbsp;</li>
      <li><a title="Follow me on Twitter" href="http://twitter.com/greydragon_th" target="_blank">{'Follow'|@translate}</a>&nbsp;|&nbsp;</li>
      <li><a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=GYVNZCNDMSD58" target="_blank">{'Coffee Fund'|@translate}</a>&nbsp;|&nbsp;</li>
      <li><a href="http://piwigo.org/ext/extension_view.php?eid=771" onclick="return false" target="_blank">{'Download'|@translate}</a></li>
    </ul>
  </div>
</div>
<form action="" method="post">
<fieldset id="GDThumb">
  <legend>{'Configuration'|@translate}</legend>
  <ul>
    <li>
      <select id="direction" name="direction" disabled>
        <option {if isset($DIRECTION) and ($DIRECTION == 'horizontal')}selected="selected"{/if} value="horizontal">{'Horizontal (Default)'|@translate}</option>
        {* <option {if isset($DIRECTION) and ($DIRECTION == 'vertical')}selected="selected"{/if} value="vertical">{'Vertical'|@translate}</option> *}
      </select>
      <label for="direction">{'Masonry Type'|@translate}</label>
    </li>
    <li>
      <select id="method" name="method" >
        <option {if $METHOD == 'crop'}selected="selected"{/if} value="crop">{'Crop (Default)'|@translate}</option>
        <option {if $METHOD == 'resize'}selected="selected"{/if} value="resize">{'Resize'|@translate}</option>
        <option {if $METHOD == 'square'}selected="selected"{/if} value="square">{'Square'|@translate}</option>
        <option {if $METHOD == 'slide'}selected="selected"{/if} value="slide">{'Slide'|@translate}</option>
      </select>
      <label for="method">{'Thumbnail Mode'|@translate}</label>
    </li>
    <li><input id="height" type="text" size="2" maxlength="3" name="height" value="{$HEIGHT}"><label for="height">{'Thumbnails max height'|@translate}&nbsp;(px)</label></li>
    <li><input id="margin" type="text" size="2" maxlength="3" name="margin" value="{$MARGIN}"><label for="margin">{'Margin between thumbnails'|@translate}&nbsp;px</label></li>
    <li><input id="nb_image_page" type="text" size="2" maxlength="3" name="nb_image_page" value="{$NB_IMAGE_PAGE}"><label for="nb_image_page">{'Number of photos per page'|@translate}</label></li>

    <li><label><i class="gd-checkbox fas {if $BIG_THUMB}fa-check-square{else}fa-square{/if}"></i>&nbsp;</span><input name="big_thumb" id="big_thumb" type="checkbox" value="1" {if $BIG_THUMB}checked="checked"{/if}>{'Double the size of the first thumbnail'|@translate}</label></li>
    <li>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<label><span class="gd-checkbox fas {if $BIG_THUMB_NOINPW}fa-check-square{else}fa-square{/if}">&nbsp;</span><input name="big_thumb_noinpw" id="big_thumb_noinpw" type="checkbox" value="1" {if $BIG_THUMB_NOINPW}checked="checked"{/if}>{'Block for Panoramic Photo Page'|@translate} (x2.2+)</label></li>
    <li><label><span class="gd-checkbox fas {if $CACHE_BIG_THUMB}fa-check-square{else}fa-square{/if}">&nbsp;</span><input name="cache_big_thumb" id="cache_big_thumb" type="checkbox" value="1" {if $CACHE_BIG_THUMB}checked="checked"{/if}>{'Cache the big thumbnails (recommended)'|@translate}</label></li>
    <li><label><span class="gd-checkbox fas {if $THUMB_ANIMATE}fa-check-square{else}fa-square{/if}">&nbsp;</span><input name="thumb_animate" id="thumb_animate" type="checkbox" value="1" {if $THUMB_ANIMATE}checked="checked"{/if}>{'Animate thumbnail on hover'|@translate}</label></li>
    <li>
      <select id="normalize_title" name="normalize_title" >
        <option {if $NORMALIZE_TITLE == 'off'}selected="selected"{/if} value="off">{'Do not Normalize (Default)'|@translate}</option>
        <option {if $NORMALIZE_TITLE == 'on'}selected="selected"{/if} value="on">{'Photo # if FileName Detected'|@translate}</option>
        <option {if $NORMALIZE_TITLE == 'desc'}selected="selected"{/if} value="desc">{'Use Description if Set'|@translate}</option>
      </select>
      <label for="normalize_title">{'Normalize Photo Title'|@translate}</label>
    </li>
    <li><label><span class="gd-checkbox fas {if $NO_WORDWRAP}fa-check-square{else}fa-square{/if}">&nbsp;</span><input name="no_wordwrap" id="no_wordwrap" type="checkbox" value="1" {if $NO_WORDWRAP}checked="checked"{/if}>{'Prevent word wrap'|@translate}</label></li>
    <li>
      <select id="thumb_mode_album" name="thumb_mode_album" >
        <option {if $THUMB_MODE_ALBUM=="top"}selected="selected"{/if} value="top">{'Overlay Top'|@translate}</option>
        <option {if $THUMB_MODE_ALBUM=="top_static"}selected="selected"{/if} value="top_static">{'Overlay Top (Static)'|@translate}</option>
        <option {if $THUMB_MODE_ALBUM=="bottom"}selected="selected"{/if} value="bottom">{'Overlay Bottom'|@translate}</option>
        <option {if $THUMB_MODE_ALBUM=="bottom_static"}selected="selected"{/if} value="bottom_static">{'Overlay Bottom (Static)'|@translate}</option>
        <option {if $THUMB_MODE_ALBUM=="overlay"}selected="selected"{/if} value="overlay">{'Overlay'|@translate}</option>
        {if $CUSTOM_CSS=="yes"}
        <option {if $THUMB_MODE_ALBUM=="overlay-ex"}selected="selected"{/if} value="overlay-ex">{'Overlay Ex'|@translate}</option>
        {/if}
        <option {if $THUMB_MODE_ALBUM=="hide"}selected="selected"{/if} value="hide">{'Hide'|@translate}</option>
      </select>
      <label for="thumb_mode_album">{'Title Display Mode (Album)'|@translate}</label>
    </li>
    <li>
      <select id="thumb_mode_photo" name="thumb_mode_photo" >
        <option {if $THUMB_MODE_PHOTO=="top"}selected="selected"{/if} value="top">{'Overlay Top'|@translate}</option>
        <option {if $THUMB_MODE_PHOTO=="top_static"}selected="selected"{/if} value="top_static">{'Overlay Top (Static)'|@translate}</option>
        <option {if $THUMB_MODE_PHOTO=="bottom"}selected="selected"{/if} value="bottom">{'Overlay Bottom'|@translate}</option>
        <option {if $THUMB_MODE_PHOTO=="bottom_static"}selected="selected"{/if} value="bottom_static">{'Overlay Bottom (Static)'|@translate}</option>
        <option {if $THUMB_MODE_PHOTO=="overlay"}selected="selected"{/if} value="overlay">{'Overlay'|@translate}</option>
        {if $CUSTOM_CSS=="yes"}
        <option {if $THUMB_MODE_PHOTO=="overlay-ex"}selected="selected"{/if} value="overlay-ex">{'Overlay Ex'|@translate}</option>
        {/if}
        <option {if $THUMB_MODE_PHOTO=="hide"}selected="selected"{/if} value="hide">{'Hide'|@translate}</option>
      </select>
      <label for="thumb_mode_photo">{'Title Display Mode (Photo)'|@translate}</label>
    </li>
    <li>
      <select id="thumb_metamode" name="thumb_metamode" >
        <option {if $THUMB_METAMODE=="merged"}selected="selected"{/if} value="merged">{'Merged (Default)'|@translate}</option>
        <option {if $THUMB_METAMODE=="merged_desc"}selected="selected"{/if} value="merged_desc">{'Merged with Description'|@translate}</option>
        <option {if $THUMB_METAMODE=="hide"}selected="selected"{/if} value="hide">{'Hide'|@translate}</option>
      </select>
      <label for="thumb_metamode">{'Metadata Display Mode'|@translate}</label>
    </li>
  </ul>
</fieldset>

<p class="admin_buttons">
  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
  <input type="submit" name="submit" value="{'Submit'|@translate}">
  <input type="button" name="cachedelete" id="cachedelete" value="{'Purge thumbnails cache'|@translate}" title="{'Delete images in GDThumb cache.'|@translate}" onclick="return confirm('{'Are you sure?'|@translate}');">
  <input type="button" name="cachebuild" id="cachebuild" value="{'Pre-cache thumbnails'|@translate}" title="{'Finds images that have not been cached and creates the cached version.'|@translate}" onclick="jQuery.gdThumb_start();">
</p>
</form>
<fieldset id="generate_cache">
  <legend>{'Pre-cache thumbnails'|@translate}</legend>
  <p class="buttons">
    <input id="startLink" value="{'Start'|@translate}" onclick="jQuery.gdThumb_start()" type="button">
    <input id="pauseLink" value="{'Pause'|@translate}" onclick="jQuery.gdThumb_pause()" type="button" disabled="disbled">
    <input id="stopLink" value="{'Stop'|@translate}" onclick="jQuery.gdThumb_stop()" type="button" disabled="disbled">
  </p>
  <div>
    <ul>
      <li>Loaded:&nbsp;<span id="loaded">0</span></li>
      <li>Remaining:&nbsp;<span id="remaining">0</span></li>
      <li>Errors:&nbsp;<span id="errors">0</span></li>
    </ul>
  </div>
  <div id="feedbackWrap" style="height:{$HEIGHT}px; min-height:{$HEIGHT}px;">
  <img id="feedbackImg">
</div>

<div id="errorList">
</div>
</fieldset>

{combine_css path=$GDTHUMB_PATH|cat:"/css/admin.css"}

{if $CUSTOM_CSS=="yes"}
  {combine_css path="themes/default/js/plugins/colorbox/style2/colorbox.css"}
  {combine_css path=$GDTHEME_PATH|cat:"admin/css/styles.css"}
  {combine_css path=$GDTHEME_PATH|cat:"css/all.min.css"}
  {combine_script id='jquery.colorbox' load='footer' require='jquery' path='themes/default/js/plugins/jquery.colorbox.min.js' }
  {combine_script id='greydragon.admin' load='footer' require='jquery' path=$GDTHEME_PATH|cat:"admin/js/admin.js" }
{else}
{html_head}{literal}
<style type="text/css">
  .gd-checkbox { display: none; }
  #generate_cache p.buttons { margin-top: 0; }
  .content select { width: 20.4em !important; margin-right: 0.6em; }
</style>
{/literal}{/html_head}
{/if}

{combine_script id='iloader' load='footer' path=$GDTHUMB_PATH|cat:"/js/image.loader.js"}
{combine_script id='admin.precache' load='footer' path=$GDTHUMB_PATH|cat:"/js/gdthumb.admin.js" require='jquery.ui.effect-slide'}