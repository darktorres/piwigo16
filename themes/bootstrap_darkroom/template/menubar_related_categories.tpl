<li id="relatedCategoriesDropdownMenu" class="nav-item dropdown">
  <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">{'Related albums'|translate}</a>
  <div class="dropdown-menu dropdown-menu-end" role="menu">
    {foreach $block->data.MENU_CATEGORIES as $cat}
      <{if isset($cat.url)}a href="{$cat.url}" {else}span{/if} class="dropdown-item" data-level="{($cat.LEVEL -1)}">
        {$cat.name}
        {if !empty($cat.count_images)}
          <span class="badge bg-primary ms-2" title="{if isset($cat.TITLE)}{$cat.TITLE}{/if}">{$cat.count_images|number_format}</span>
        {/if}
        {if !empty($cat.count_categories) }
          <span class="badge bg-secondary ms-2" title="{'sub-albums'|translate}">{$cat.count_categories|number_format}</span>
        {/if}
      </{if isset($cat.url)}a{else}span{/if}>
    {/foreach}
  </div>
</li>
{footer_script}<script>
  var relatedCategoriesDropdownMenu = document.getElementById('relatedCategoriesDropdownMenu');
  if (relatedCategoriesDropdownMenu) {
    relatedCategoriesDropdownMenu.addEventListener('show.bs.dropdown', function() {
      var items = this.querySelectorAll('.dropdown-item');
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