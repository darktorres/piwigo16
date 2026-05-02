{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_script id='element_set_ranks' load='footer' path='admin/themes/default/js/element_set_ranks.js'}

<form action="{$F_ACTION}" method="post" id="element_set_ranks">
{if !empty($thumbnails)}
  <fieldset>
    <legend><span class="icon-sort-alt-down icon-blue"></span>{'Manual order'|@translate}</legend>
    {if !empty($thumbnails)}
    <p>{'Drag to re-order'|@translate}</p>
    <ul class="thumbnails">
      {foreach from=$thumbnails item=thumbnail}
      <li class="rank-of-image">
        <img src="{$thumbnail.TN_SRC}" class="thumbnail" alt="{$thumbnail.NAME|@replace:'"':' '}" title="{$thumbnail.NAME|@replace:'"':' '}"  style="width:{$thumbnail.SIZE[0]}px; height:{$thumbnail.SIZE[1]}px; ">
        <input type="text" name="rank_of_image[{$thumbnail.ID}]" value="{$thumbnail.RANK}" hidden>
      </li>
      {/foreach}
    </ul>
    {/if}
  </fieldset>
{/if}

  <fieldset>
    <legend><span class="icon-sort-name-up icon-red"></span>{'Sort order'|@translate}</legend>
    <p class="field">
      <label class="font-checkbox">
        <span class="icon-dot-circled"></span>
        <input type="radio" name="image_order_choice" id="image_order_default" value="default"{if $image_order_choice=='default'} checked="checked"{/if}>
        {'Use the default photo sort order'|@translate}
      </label>
    </p>
    <p class="field">
      <label class="font-checkbox">
        <span class="icon-dot-circled"></span>
        <input type="radio" name="image_order_choice" id="image_order_rank" value="rank"{if $image_order_choice=='rank'} checked="checked"{/if}>
        {'manual order'|translate}
      </label>
    </p>
    <p class="field">
      <label class="font-checkbox">
        <span class="icon-dot-circled"></span>
        <input type="radio" name="image_order_choice" id="image_order_user_define" value="user_define"{if $image_order_choice=='user_define'} checked="checked"{/if}>
        {'automatic order'|@translate}
      </label>
      <div id="image_order_user_define_options">
      {foreach from=$image_order item=order}
      <p class="field">
        <select name="image_order[]">
          {html_options options=$image_order_options selected=$order}
        </select>
      </p>
      {/foreach}
      </div>
  </fieldset>

  <div class="savebar-footer">
    <div class="savebar-footer-start">
      <div class="savebar-footer-block">
        <div class="switch-input">
          <label class="switch">
            <input type="checkbox" name="image_order_subcats" id="image_order_subcats">
            <span class="slider round"></span>
          </label>
        </div>
        <label class="switch-label" for="apply_on_sub"><span>{'Apply to sub-albums'|@translate}</span></label>
      </div>
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
      <button id="applyAction" class="buttonLike" type="submit" name="submit"><i class="icon-floppy"></i>{'Save order'|@translate}</button>
    </div>
  </div>
</div>

</form>
