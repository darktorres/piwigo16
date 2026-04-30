{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}

{footer_script}
(function() {
  document.querySelectorAll(".menuPos").forEach(function(el) { el.style.display = 'none'; });
  document.querySelectorAll(".drag_button").forEach(function(el) { el.style.display = ''; });
  document.querySelectorAll(".menuLi").forEach(function(el) { el.style.cursor = 'move'; });

  /* Native drag-and-drop sortable for .menuUl */
  var menuUl = document.querySelector('.menuUl');
  if (menuUl) {
    var dragSrc = null;

    Array.from(menuUl.children).forEach(function(item) {
      item.setAttribute('draggable', 'true');
      item.addEventListener('dragstart', function(e) {
        dragSrc = this;
        e.dataTransfer.effectAllowed = 'move';
        this.style.opacity = '0.8';
      });
      item.addEventListener('dragend', function() {
        this.style.opacity = '';
        Array.from(menuUl.children).forEach(function(li) { li.classList.remove('drag-over'); });
      });
      item.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        return false;
      });
      item.addEventListener('dragenter', function() {
        this.classList.add('drag-over');
      });
      item.addEventListener('dragleave', function() {
        this.classList.remove('drag-over');
      });
      item.addEventListener('drop', function(e) {
        e.stopPropagation();
        if (dragSrc !== this) {
          var items = Array.from(menuUl.children);
          var srcIdx = items.indexOf(dragSrc);
          var tgtIdx = items.indexOf(this);
          if (srcIdx < tgtIdx) {
            menuUl.insertBefore(dragSrc, this.nextSibling);
          } else {
            menuUl.insertBefore(dragSrc, this);
          }
        }
        return false;
      });
    });
  }

  document.querySelectorAll("input[name^='hide_']").forEach(function(input) {
    input.addEventListener('click', function() {
      var men = this.name.split('hide_');
      var menuItem = document.getElementById("menu_" + men[1]);
      if (menuItem) {
        if (this.checked) {
          menuItem.classList.add('menuLi_hidden');
        } else {
          menuItem.classList.remove('menuLi_hidden');
        }
      }
    });
  });

  var menuOrderingForm = document.getElementById("menuOrdering");
  if (menuOrderingForm) {
    menuOrderingForm.addEventListener('submit', function() {
      var items = Array.from(menuUl ? menuUl.children : []);
      for (var i = 0; i < items.length; i++) {
        var men = items[i].id.split('menu_');
        var posInput = document.getElementsByName('pos_' + men[1])[0];
        if (posInput) posInput.value = i + 1;
      }
    });
  }
}());
{/footer_script}

{html_style}
.font-checkbox i {
  margin-left:5px;
}
{/html_style}

<form id="menuOrdering" action="{$F_ACTION}" method="post">
  <ul class="menuUl">
    {foreach from=$blocks item=block name="block_loop"}
    <li class="menuLi {if $block.pos<0}menuLi_hidden{/if}" id="menu_{$block.reg->get_id()}">
      <p>
        <span>
          <label class="font-checkbox"><strong>{'Hide'|@translate}</strong><i class="icon-check"></i><input type="checkbox" name="hide_{$block.reg->get_id()}" {if $block.pos<0}checked="checked"{/if}></label>
        </span>

        <img src="{$themeconf.admin_icon_dir}/cat_move.png" class="drag_button" style="display:none;" alt="{'Drag to re-order'|@translate}" title="{'Drag to re-order'|@translate}">
        <strong>{$block.reg->get_name()|@translate}</strong> ({$block.reg->get_id()})
      </p>

      {if $block.reg->get_owner() != 'piwigo'}
      <p class="menuAuthor">
        {'Author'|@translate}: <i>{$block.reg->get_owner()}</i>
      </p>
      {/if}

      <p class="menuPos">
        <label>
          {'Position'|@translate} :
          <input type="text" size="4" name="pos_{$block.reg->get_id()}" maxlength="4" value="{math equation="abs(pos)" pos=$block.pos}">
        </label>
      </p>
    </li>
    {/foreach}
  </ul>
  <div class="savebar-footer">
    <div class="savebar-footer-start">
    </div>
    <div class="savebar-footer-end">
    {if isset($save_success)}
        <div class="savebar-footer-block">
          <div class="badge info-message">
            <i class="icon-ok"></i>{$save_success}
          </div>
        </div>
    {/if}
        <div class="savebar-footer-block">
          <button class="buttonLike"  type="submit" name="submit" {if $isWebmaster != 1}disabled{/if}><i class="icon-floppy"></i> {'Save Settings'|@translate}</button>
        </div>
      </div>
  </div>

</form>
