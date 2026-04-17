<li id="categoriesDropdownMenu" class="nav-item dropdown">
  <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">{'Albums'|translate}</a>
  <div class="dropdown-menu dropdown-menu-end" role="menu">
    {assign var='ref_level' value=0}
    {foreach $block->data.MENU_CATEGORIES as $cat}
      <a class="dropdown-item{if $cat.SELECTED} active{/if}" data-level="{($cat.LEVEL -1)}" href="{$cat.URL}">
        {$cat.NAME}
        {if $cat.count_images > 0}
          <span class="badge bg-secondary ms-2" title="{$cat.TITLE}">{$cat.count_images|number_format}</span>
        {/if}
        {if !empty($cat.icon_ts)}
          <img title="{$cat.icon_ts.TITLE}"
            src="{$ROOT_URL}{$themeconf.icon_dir}/recent{if $cat.icon_ts.IS_CHILD_DATE}_by_child{/if}.png" class="icon"
            alt="(!)">
        {/if}
      </a>
    {/foreach}
    <div class="dropdown-divider"></div>
    <div class="dropdown-header">{$block->data.NB_PICTURE|translate_dec:'%d photo':'%d photos'}</div>
  </div>
</li>
{footer_script}<script>
  var categoriesDropdownMenu = document.getElementById('categoriesDropdownMenu');
  if (categoriesDropdownMenu) {
    categoriesDropdownMenu.addEventListener('show.bs.dropdown', function() {
      var items = this.querySelectorAll('a.dropdown-item');
      items.forEach(function(item) {
        var level = parseInt(item.dataset.level);
        var paddingLeft = window.getComputedStyle(item).paddingLeft;
        var padding = parseInt(paddingLeft);
        if (level > 0) {
          item.style.paddingLeft = (padding + 10 * level) + 'px';
        }
      });
    });
  }
</script>{/footer_script}