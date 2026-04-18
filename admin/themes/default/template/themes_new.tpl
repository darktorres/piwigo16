{include file='inc/colorbox.inc.tpl'}

{if $vite_themes_new}
<script type="module" src="admin/themes/default/js/dist/{$vite_themes_new}"></script>
{/if}

{if not empty($new_themes)}
  <div class="themeBoxes">
    {foreach $new_themes as $theme}
      <div class="themeBox">
        <div class="themeShot"><a href="{$theme.screenshot}" class="preview-box" title="{$theme.name}"><img
              src="{$theme.screenshot}" onerror="this.src='{$default_screenshot}'"></a></div>
        <div class="themeName" title="{$theme.name}">{$theme.name}</div>
        <div class="themeActions"><a href="{$theme.install_url}">{'Install'|translate}</a></div>
      </div>
    {/foreach}
  </div> <!-- themeBoxes -->
{else}
  <p>{'There is no other theme available.'|translate}</p>
{/if}