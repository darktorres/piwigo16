{combine_css path='node_modules/tom-select/dist/css/tom-select.default.css'}

<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

{if $vite_cat_perm}
<script type="module" src="admin/themes/default/js/dist/{$vite_cat_perm}"></script>
{/if}

<form action="{$F_ACTION}" method="post" id="categoryPermissions">

  <fieldset>
    <legend><span class="icon-lock icon-yellow"></span>{'Access type'|translate}</legend>

    <p id="selectStatus">
      <label class="font-checkbox">
        <span class="icon-dot-circled"></span>
        <input type="radio" name="status" value="public" {if not $private}checked="checked" {/if}>
        <strong>{'public'|translate}</strong> : <em>{'any visitor can see this album'|translate}</em>
      </label>
      <br>
      <label class="font-checkbox">
        <span class="icon-dot-circled"></span>
        <input type="radio" name="status" value="private" {if $private}checked="checked" {/if}>
        <strong>{'private'|translate}</strong> :
        <em>{'visitors need to login and have the appropriate permissions to see this album'|translate}</em>
      </label>
    </p>
  </fieldset>

  <fieldset id="privateOptions">
    <legend>{'Groups and users'|translate}</legend>

    <p>
      {if count($groups) > 0}
        <strong>{'Permission granted for groups'|translate}</strong>
        <br>
        <select data-selectize="groups" data-value="{$groups_selected|json_encode|escape:html}"
          placeholder="{'Type in a search term'|translate}" name="groups[]" multiple style="width:600px;"></select>
      {else}
        {'There is no group in this gallery.'|translate} <a href="admin.php?page=group_list"
          class="externalLink">{'Group management'|translate}</a>
      {/if}
    </p>

    <p>
      <strong>{'Permission granted for users'|translate}</strong>
      <br>
      <select data-selectize="users" data-value="{$users_selected|json_encode|escape:html}"
        placeholder="{'Type in a search term'|translate}" name="users[]" multiple style="width:600px;"></select>
    </p>

    {if isset($nb_users_granted_indirect) && $nb_users_granted_indirect>0}
      <p>
        {'%u users have automatic permission because they belong to a granted group.'|translate:$nb_users_granted_indirect}
        <a href="#" class="toggle-indirectPermissions" style="display:none">{'hide details'|translate}</a>
        <a href="#" class="toggle-indirectPermissions">{'show details'|translate}</a>

      <ul id="indirectPermissionsDetails" style="display:none">
        {foreach $user_granted_indirect_groups as $group_details}
          <li><strong>{$group_details.group_name}</strong> : {$group_details.group_users}</li>
        {/foreach}
      </ul>
      </p>
    {/if}

    {*
  <h4>{'Groups'|translate}</h4>

  <fieldset>
    <legend>{'Permission granted'|translate}</legend>
    <ul>
      {foreach $group_granted_ids as $id}
      <li><label><input type="checkbox" name="deny_groups[]" value="{$id}"> {$all_groups[$id]}</label></li>
      {/foreach}
    </ul>
    <input class="submit" type="submit" name="deny_groups_submit" value="{'Deny selected groups'|translate}">
  </fieldset>

  <fieldset>
    <legend>{'Permission denied'|translate}</legend>
    <ul>
      {foreach $group_denied_ids as $id}
      <li><label><input type="checkbox" name="grant_groups[]" value="{$id}"> {$all_groups[$id]}</label></li>
      {/foreach}
    </ul>
    <input class="submit" type="submit" name="grant_groups_submit" value="{'Grant selected groups'|translate}">
    <label><input type="checkbox" name="apply_on_sub">{'Apply to sub-albums'|translate}</label>
  </fieldset>

  <h4>{'Users'|translate}</h4>

  <fieldset>
    <legend>{'Permission granted'|translate}</legend>
    <ul>
      {foreach $user_granted_direct_ids as $id}
      <li><label><input type="checkbox" name="deny_users[]" value="{$id}"> {$all_users[$id]}</label></li>
      {/foreach}
    </ul>
    <input class="submit" type="submit" name="deny_users_submit" value="{'Deny selected users'|translate}">
  </fieldset>

  <fieldset>
    <legend>{'Permission granted thanks to a group'|translate}</legend>
    {if isset($user_granted_indirects) }
    <ul>
      {foreach $user_granted_indirects as $user_group}
      <li>{$user_group.USER} ({$user_group.GROUP})</li>
      {/foreach}
    </ul>
    {/if}
  </fieldset>

  <fieldset>
    <legend>{'Permission denied'|translate}</legend>
    <ul>
      {foreach $user_denied_ids as $id}
      <li><label><input type="checkbox" name="grant_users[]" value="{$id}"> {$all_users[$id]}</label></li>
      {/foreach}
    </ul>
    <input class="submit" type="submit" name="grant_users_submit" value="{'Grant selected users'|translate}">
    <label><input type="checkbox" name="apply_on_sub">{'Apply to sub-albums'|translate}</label>
  </fieldset>
*}
  </fieldset>

  <p style="margin:12px;text-align:left;">
    <button name="submit" type="submit" class="buttonLike">
      <i class="icon-floppy"></i> {'Save Settings'|translate}
    </button>

    <label id="applytoSubAction" class="font-checkbox">
      <span class="icon-check"></span>
      <input type="checkbox" name="apply_on_sub" {if $INHERIT}checked="checked" {/if}>
      {'Apply to sub-albums'|translate}
    </label>
  </p>

  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
</form>