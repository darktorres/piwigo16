{footer_script}<script>
  const cat_nav = '{$CATEGORIES_NAV|escape:javascript}';
</script>{/footer_script}

{if $vite_element_set_ranks}
<script type="module" src="admin/themes/default/js/dist/{$vite_element_set_ranks}"></script>
{/if}

<form action="{$F_ACTION}" method="post">
  {if !empty($thumbnails)}
    <p><input type="submit" value="{'Submit'|translate}" name="submit"></p>
    <fieldset>
      <legend><span class="icon-sort-alt-down icon-blue"></span>{'Manual order'|translate}</legend>
      {if !empty($thumbnails)}
        <p>{'Drag to re-order'|translate}</p>
        <ul class="thumbnails">
          {foreach $thumbnails as $thumbnail}
            <li class="rank-of-image">
              <img src="{$thumbnail.TN_SRC}" class="thumbnail" alt="{$thumbnail.NAME|replace:'"':' '}"
          title="{$thumbnail.NAME|replace:'"':' '}"
                style="width:{$thumbnail.SIZE[0]}px; height:{$thumbnail.SIZE[1]}px; ">
              <input type="text" name="rank_of_image[{$thumbnail.ID}]" value="{$thumbnail.RANK}" style="display:none">
            </li>
          {/foreach}
        </ul>
      {/if}
    </fieldset>
  {/if}

  <fieldset>
    <legend><span class="icon-sort-name-up icon-red"></span>{'Sort order'|translate}</legend>
    <p class="field">
      <label class="font-checkbox">
        <span class="icon-dot-circled"></span>
        <input type="radio" name="image_order_choice" id="image_order_default" value="default"
          {if $image_order_choice=='default'} checked="checked" {/if}>
        {'Use the default photo sort order'|translate}
      </label>
    </p>
    <p class="field">
      <label class="font-checkbox">
        <span class="icon-dot-circled"></span>
        <input type="radio" name="image_order_choice" id="image_order_rank" value="rank"
          {if $image_order_choice=='rank'} checked="checked" {/if}>
        {'manual order'|translate}
      </label>
    </p>
    <p class="field">
      <label class="font-checkbox">
        <span class="icon-dot-circled"></span>
        <input type="radio" name="image_order_choice" id="image_order_user_define" value="user_define"
          {if $image_order_choice=='user_define'} checked="checked" {/if}>
        {'automatic order'|translate}
      </label>
    <div id="image_order_user_define_options">
      {foreach $image_order as $order}
        <p class="field">
          <select name="image_order[]">
            {html_options options=$image_order_options selected=$order}
          </select>
        </p>
      {/foreach}
    </div>
  </fieldset>
  <p>
    <button name="submit" type="submit" class="buttonLike">
      <i class="icon-floppy"></i> {'Save Settings'|translate}
    </button>

    <label class="font-checkbox">
      <span class="icon-check"></span>
      <input type="checkbox" name="image_order_subcats" id="image_order_subcats">
      {'Apply to sub-albums'|translate}
    </label>
  </p>
</form>