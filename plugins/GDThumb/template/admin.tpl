{combine_css path='plugins/GDThumb/css/admin_page.css' order=-10}
<div class="titrePage">
  <h2>GDThumb - {$GDTHUMB_VERSION}</h2>
  <div class="left-links">
    <ul>
      <li><a href="http://blog.dragonsoft.us/piwigo/" target="_blank">{'Home'|translate}</a>&nbsp;|&nbsp;</li>
      <li><a href="http://piwigo.org/forum/viewtopic.php?id=24413"
          target="_blank">{'Support'|translate}</a>&nbsp;|&nbsp;</li>
      <li><a href="http://piwigo.org/ext/extension_view.php?eid=771" onclick="return false"
          target="_blank">{'Download'|translate}</a></li>
    </ul>
  </div>
</div>
<form action="" method="post">
  <fieldset id="GDThumb">
    <legend>{'Configuration'|translate}</legend>
    <ul>
      <li>
        <select id="method" name="method">
          <option {if $METHOD == 'crop'}selected="selected" {/if} value="crop">{'Crop (Default)'|translate}</option>
          <option {if $METHOD == 'resize'}selected="selected" {/if} value="resize">{'Resize'|translate}</option>
        </select>
        <label for="method">{'Thumbnail Mode'|translate}</label>
      </li>
      <li><input id="margin" type="text" size="2" maxlength="3" name="margin" value="{$MARGIN}"><label
          for="margin">{'Margin between thumbnails'|translate}&nbsp;px</label></li>
      <li>
        <select id="normalize_title" name="normalize_title">
          <option {if $NORMALIZE_TITLE == 'off'}selected="selected" {/if} value="off">
            {'Do not Normalize (Default)'|translate}</option>
          <option {if $NORMALIZE_TITLE == 'on'}selected="selected" {/if} value="on">
            {'Photo # if FileName Detected'|translate}</option>
          <option {if $NORMALIZE_TITLE == 'desc'}selected="selected" {/if} value="desc">
            {'Use Description if Set'|translate}</option>
        </select>
        <label for="normalize_title">{'Normalize Photo Title'|translate}</label>
      </li>
      <li><label><input name="no_wordwrap" id="no_wordwrap" type="checkbox" value="1" {if $NO_WORDWRAP}checked="checked"
            {/if}>{'Prevent word wrap'|translate}</label></li>
      <li>
        <select id="thumb_mode_album" name="thumb_mode_album">
          <option {if $THUMB_MODE_ALBUM=="top"}selected="selected" {/if} value="top">{'Overlay Top'|translate}</option>
          <option {if $THUMB_MODE_ALBUM=="top_static"}selected="selected" {/if} value="top_static">
            {'Overlay Top (Static)'|translate}</option>
          <option {if $THUMB_MODE_ALBUM=="bottom"}selected="selected" {/if} value="bottom">{'Overlay Bottom'|translate}
          </option>
          <option {if $THUMB_MODE_ALBUM=="bottom_static"}selected="selected" {/if} value="bottom_static">
            {'Overlay Bottom (Static)'|translate}</option>
          <option {if $THUMB_MODE_ALBUM=="overlay"}selected="selected" {/if} value="overlay">{'Overlay'|translate}
          </option>
          <option {if $THUMB_MODE_ALBUM=="overlay-ex"}selected="selected" {/if} value="overlay-ex">
            {'Overlay Ex'|translate}</option>
          <option {if $THUMB_MODE_ALBUM=="hide"}selected="selected" {/if} value="hide">{'Hide'|translate}</option>
        </select>
        <label for="thumb_mode_album">{'Title Display Mode (Album)'|translate}</label>
      </li>
      <li>
        <select id="thumb_mode_photo" name="thumb_mode_photo">
          <option {if $THUMB_MODE_PHOTO=="top"}selected="selected" {/if} value="top">{'Overlay Top'|translate}</option>
          <option {if $THUMB_MODE_PHOTO=="top_static"}selected="selected" {/if} value="top_static">
            {'Overlay Top (Static)'|translate}</option>
          <option {if $THUMB_MODE_PHOTO=="bottom"}selected="selected" {/if} value="bottom">{'Overlay Bottom'|translate}
          </option>
          <option {if $THUMB_MODE_PHOTO=="bottom_static"}selected="selected" {/if} value="bottom_static">
            {'Overlay Bottom (Static)'|translate}</option>
          <option {if $THUMB_MODE_PHOTO=="overlay"}selected="selected" {/if} value="overlay">{'Overlay'|translate}
          </option>
          <option {if $THUMB_MODE_PHOTO=="overlay-ex"}selected="selected" {/if} value="overlay-ex">
            {'Overlay Ex'|translate}</option>
          <option {if $THUMB_MODE_PHOTO=="hide"}selected="selected" {/if} value="hide">{'Hide'|translate}</option>
        </select>
        <label for="thumb_mode_photo">{'Title Display Mode (Photo)'|translate}</label>
      </li>
      <li>
        <select id="thumb_metamode" name="thumb_metamode">
          <option {if $THUMB_METAMODE=="merged"}selected="selected" {/if} value="merged">{'Merged (Default)'|translate}
          </option>
          <option {if $THUMB_METAMODE=="merged_desc"}selected="selected" {/if} value="merged_desc">
            {'Merged with Description'|translate}</option>
          <option {if $THUMB_METAMODE=="hide"}selected="selected" {/if} value="hide">{'Hide'|translate}</option>
        </select>
        <label for="thumb_metamode">{'Metadata Display Mode'|translate}</label>
      </li>
    </ul>
  </fieldset>

  <p class="admin_buttons">
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
    <input type="submit" name="submit" value="{'Submit'|translate}">
    <input type="submit" name="cachedelete" id="cachedelete" value="{'Purge thumbnails cache'|translate}"
      title="{'Delete images in GDThumb cache.'|translate}" onclick="return confirm('{'Are you sure?'|translate}');">
    <input type="button" name="cachebuild" id="cachebuild" value="{'Pre-cache thumbnails'|translate}"
      title="{'Finds images that have not been cached and creates the cached version.'|translate}">
  </p>
</form>
<fieldset id="generate_cache">
  <legend>{'Pre-cache thumbnails'|translate}</legend>
  <p class="buttons">
    <input id="startLink" value="{'Start'|translate}" type="button">
    <input id="pauseLink" value="{'Pause'|translate}" type="button"
      disabled="disabled">
    <input id="stopLink" value="{'Stop'|translate}" type="button" disabled="disabled">
  </p>
  <div>
    <ul>
      <li>Loaded:&nbsp;<span id="loaded">0</span></li>
      <li>Remaining:&nbsp;<span id="remaining">0</span></li>
      <li>Errors:&nbsp;<span id="errors">0</span></li>
    </ul>
  </div>
  <div id="feedbackWrap" style="height:200px; min-height:200px;">
    <img id="feedbackImg">
  </div>

  <div id="errorList">
  </div>
</fieldset>

{combine_css path=$GDTHUMB_PATH|cat:"/css/admin.css"}
{html_head}

{/html_head}

<script type="module" src="{$ROOT_URL}admin/themes/default/js/dist/{$vite_gdthumb_admin}"></script>
