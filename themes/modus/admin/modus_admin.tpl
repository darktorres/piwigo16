{combine_css path='themes/modus/css/modus_admin.css' order=-10}
{include file='inc/colorbox.inc.tpl'}
{combine_script id='nouislider' load='footer' path='node_modules/nouislider/dist/nouislider.min.js'}
{combine_css path='node_modules/nouislider/dist/nouislider.min.css'}

{footer_script}<script type="module">
  import { sprintf } from './admin/themes/default/js/dist/{$vite_common}';
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#modus-config input[type=checkbox]').forEach(function(cb) {
      cb.addEventListener('change', function() {
        var prev = this.previousElementSibling;
        if (prev) {
          prev.classList.toggle('icon-check');
          prev.classList.toggle('icon-check-empty');
        }
      });
    });

    var squareThumbsToggle = document.querySelector('input[name=use_album_square_thumbs]');
    if (squareThumbsToggle) {
      squareThumbsToggle.addEventListener('change', function() {
        var el = document.getElementById('album_square_thumbs');
        if (el) el.style.display = (el.style.display === 'none') ? '' : 'none';
      });
    }

    var albumThumbSlider = document.getElementById('album_thumb_size');
    if (albumThumbSlider) {
      noUiSlider.create(albumThumbSlider, {
        start: [{$ALBUM_THUMB_SIZE}],
        range: { min: 200, max: 400 },
        step: 1,
        connect: [true, false],
      });
      albumThumbSlider.noUiSlider.on('slide', function(values) {
        var val = Math.round(parseFloat(values[0]));
        var info = document.getElementById('album_thumb_size_info');
        if (info) info.innerHTML = sprintf("{'%d pixels'|translate}", val);
      });
      albumThumbSlider.noUiSlider.on('change', function(values) {
        var val = Math.round(parseFloat(values[0]));
        var input = document.querySelector("input[name=album_thumb_size]");
        if (input) input.value = val;
      });
    }
    GLightbox({ selector: '.themeBoxes a' });

    document.querySelectorAll("input[name='skin']").forEach(function(radio) {
      radio.addEventListener('change', function() {
        document.querySelectorAll("input[name='skin']").forEach(function(r) {
          var box = r.closest('.themeBoxModusConfig');
          if (box) box.classList.remove('themeDefault');
        });
        var myBox = this.closest('.themeBoxModusConfig');
        if (myBox) myBox.classList.add('themeDefault');
      });
    });
  });
</script>{/footer_script}

<h2>{'Modus theme config'|translate}</h2>

<form method="post" action="" id="modus-config">

  <fieldset class="fieldsetModusConfig">
    <legend class="legendModusConfig">{'Skin'|translate}</legend>
    <div class="themeBoxes font-checkbox">
      {foreach $available_skins as $skin_code => $skin_name}
        <div class="{if $skin_code==$SKIN}themeDefault{/if} themeBoxModusConfig">
          <label class="font-checkbox">
            <div class="themeNameModusConfig">
              <span class="icon-dot-circled"></span>
              <input type="radio" name="skin" value="{$skin_code}" {if $skin_code==$SKIN}checked{/if}>
              {$skin_name}
            </div>
            <div class="themeShot">
              <img src="{$ROOT_URL}themes/modus/skins/{$skin_code}-screenshot.jpg" />
            </div>
          </label>
          <a href="{$ROOT_URL}themes/modus/skins/{$skin_code}-screenshot.jpg" class="icon-zoom-in"
            title="{$skin_name}">{'Preview'|translate}</a>
        </div>
      {/foreach}
    </div>
    {*
<select name="skin">
		{html_options options=$available_skins selected=$SKIN}
</select>
*}
  </fieldset>

  <fieldset>
    <legend>{'Album thumbnails'|translate}</legend>
    <label>
      <span class="graphicalCheckbox icon-check{if not $use_album_square_thumbs}-empty{/if}"></span>
      <input type="checkbox" name="use_album_square_thumbs" {if $use_album_square_thumbs} checked="checked" {/if}>
      <b>{'Use square thumbs'|translate}</b>
    </label>

    <div id="album_square_thumbs" {if not $use_album_square_thumbs} style="display:none" {/if}>
      <div id="album_thumb_size"></div>
      <span id="album_thumb_size_info">{'%d pixels'|translate|sprintf:$ALBUM_THUMB_SIZE}</span>
      <input type="hidden" name="album_thumb_size" value="{$ALBUM_THUMB_SIZE}">
    </div>

  </fieldset>

  <fieldset>
    <legend>{'Default sizes'|translate}</legend>

    <label>{'Default size for thumbnails'|translate}
      <select name="index_photo_deriv">
        {html_options options=$available_derivatives selected=$INDEX_PHOTO_DERIV}
      </select>
    </label>
    <br>
    <label>{'Default size for thumbnails on high density display (retina)'|translate}
      <select name="index_photo_deriv_hdpi">
        {html_options options=$available_derivatives selected=$INDEX_PHOTO_DERIV_HDPI}
      </select>
    </label>
  </fieldset>

  <fieldset>
    <legend>{'Page banner'|translate}</legend>
    <label>
      <span class="graphicalCheckbox icon-check{if not $DISPLAY_PAGE_BANNER}-empty{/if}"></span>
      <input type="checkbox" name="display_page_banner" {if $DISPLAY_PAGE_BANNER} checked="checked" {/if}>
      <b>{'Display page banner'|translate}</b>
    </label>
  </fieldset>

  {*
<fieldset><legend>Full row thumbnail layout</legend>
Automatically applied for selected derivatives if max_width > max_height*1.5
</fieldset>
*}

  <p class="formButtons">
    <input type="submit" value="{'Save Settings'|translate}" />
  </p>
</form>