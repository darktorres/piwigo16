{if $vite_menubar}
<script type="module" src="admin/themes/default/js/dist/{$vite_menubar}"></script>
{/if}

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