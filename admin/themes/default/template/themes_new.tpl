{include file='include/colorbox.inc.tpl'}
{combine_script id='themes_new' load='footer' path='admin/themes/default/js/themes_new.js'}

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