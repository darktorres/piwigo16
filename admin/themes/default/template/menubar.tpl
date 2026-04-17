{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_script id='sortablejs' load='footer' path='node_modules/sortablejs/Sortable.min.js'}

{footer_script require='sortablejs'}<script>
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll(".menuPos").forEach(function(el) { el.style.display = 'none'; });
    document.querySelectorAll(".drag_button").forEach(function(el) { el.style.display = ''; });
    document.querySelectorAll(".menuLi").forEach(function(el) { el.style.cursor = "move"; });

    var menuUl = document.querySelector(".menuUl");
    var menuSortable = menuUl ? Sortable.create(menuUl, { animation: 150 }) : null;

    document.querySelectorAll("input[name^='hide_']").forEach(function(el) {
      el.addEventListener('click', function() {
        var men = this.name.split('hide_');
        var menuEl = document.getElementById("menu_" + men[1]);
        if (menuEl) menuEl.classList.toggle('menuLi_hidden', this.checked);
      });
    });

    var menuOrdering = document.getElementById("menuOrdering");
    if (menuOrdering) {
      menuOrdering.addEventListener('submit', function() {
        if (menuSortable) {
          var ar = menuSortable.toArray();
          for (var i = 0; i < ar.length; i++) {
            var men = ar[i].split('menu_');
            var posInput = document.getElementsByName('pos_' + men[1])[0];
            if (posInput) posInput.value = i + 1;
          }
        }
      });
    }
  });
</script>{/footer_script}

{html_style}<style>
  .font-checkbox i {
    margin-left: 5px;
  }
</style>{/html_style}

<form id="menuOrdering" action="{$F_ACTION}" method="post">
  <ul class="menuUl">
    {foreach $blocks as $block}
      <li class="menuLi {if $block.pos<0}menuLi_hidden{/if}" id="menu_{$block.reg->get_id()}">
        <p>
          <span>
            <label class="font-checkbox"><strong>{'Hide'|translate}</strong><i class="icon-check"></i><input
                type="checkbox" name="hide_{$block.reg->get_id()}" {if $block.pos<0}checked="checked" {/if}></label>
          </span>

          <img src="{$themeconf.admin_icon_dir}/cat_move.png" class="drag_button" style="display:none;"
            alt="{'Drag to re-order'|translate}" title="{'Drag to re-order'|translate}">
          <strong>{$block.reg->get_name()|translate}</strong> ({$block.reg->get_id()})
        </p>

        {if $block.reg->get_owner() != 'piwigo'}
          <p class="menuAuthor">
            {'Author'|translate}: <i>{$block.reg->get_owner()}</i>
          </p>
        {/if}

        <p class="menuPos">
          <label>
            {'Position'|translate} :
            <input type="text" size="4" name="pos_{$block.reg->get_id()}" maxlength="4"
              value="{math equation="abs(pos)" pos=$block.pos}">
          </label>
        </p>
      </li>
    {/foreach}
  </ul>
  <p class="menuSubmit">
    <button name="submit" type="submit" class="buttonLike" {if $isWebmaster != 1}disabled{/if}>
      <i class="icon-floppy"></i> {'Save Settings'|translate}
    </button>
  </p>

</form>