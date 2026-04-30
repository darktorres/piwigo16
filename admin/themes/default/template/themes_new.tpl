{include file='include/colorbox.inc.tpl'} 
{footer_script}
window.addEventListener("load", function() {
  document.querySelectorAll('.themeBox').forEach(function(box) {
    var screenImage = box.querySelector(".preview-box img");
    var previewBox = box.querySelector(".preview-box");
    if (!screenImage || !previewBox) return;

    var imageW = screenImage.clientWidth;
    var imageH = screenImage.clientHeight;
    var size = previewBox.clientWidth;

    if (imageW > imageH) {
      screenImage.style.height = size + 'px';
      screenImage.style.width = (imageW * size / imageH) + 'px';
    } else {
      screenImage.style.width = size + 'px';
      screenImage.style.height = (imageH * size / imageW) + 'px';
    }
  });
});
{/footer_script}

{if not empty($new_themes)}
<div class="themeBoxes">
{foreach from=$new_themes item=theme name=themes_loop}
<div class="themeBox">
  <div class="themeShot"><a href="{$theme.screenshot}" class="preview-box" title="{$theme.name}"><img src="{$theme.screenshot}" onerror="this.src='{$default_screenshot}'"></a></div>
  <div class="themeName" title="{$theme.name}">{$theme.name}</div>
  <div class="themeActions"><a href="{$theme.install_url}">{'Install'|@translate}</a></div>
</div>
{/foreach}
</div> <!-- themeBoxes -->
{else}
<p>{'There is no other theme available.'|@translate}</p>
{/if}