{combine_css path=$ADMINTOOLS_PATH|cat:'template/public_style.css'}
{combine_css path='admin/themes/default/fontello/css/fontello.css'}
{combine_css path=$ADMINTOOLS_PATH|cat:'template/fontello/css/fontello-ato.css'}
{combine_css path=$ADMINTOOLS_PATH|cat:'template/public_controller.css' order=-10}

{if isset($ato.QUICK_EDIT)}
  {combine_script id='mousetrap' load='footer' path='node_modules/mousetrap/mousetrap.js'}
  {combine_script id='tom-select' load='footer' path='node_modules/tom-select/dist/js/tom-select.complete.js'}
  {combine_css path='node_modules/tom-select/dist/css/tom-select.default.css'}
{/if}

{if isset($ato.U_SET_REPRESENTATIVE)}
  {assign var="initRepresentative" value=['imageId' => $current.id, 'categoryId' => $ato.CATEGORY_ID]|@json_encode}
{else}
  {assign var="initRepresentative" value='null'}
{/if}

{footer_script}<script type="application/json" id="admintools-public-config">
{
  "urlWS": "{$ROOT_URL}ws.php?format=json&method=",
  "urlSelf": "{$ato.U_SELF|escape:'html'}",
  {if isset($ato.MULTIVIEW)}
  "multiView": {
    "view_as": {$ato.MULTIVIEW.view_as},
    "theme": "{$ato.MULTIVIEW.theme|escape:'html'}",
    "lang": "{$ato.MULTIVIEW.lang|escape:'html'}"
  },
  {else}
  "multiView": {},
  {/if}
  "deleteCache": {if isset($ato.DELETE_CACHE) and $ato.DELETE_CACHE}true{else}false{/if},
  "defaultOpen": {intval($ato.DEFAULT_OPEN)},
  "initMobile": {if isset($themeconf.mobile) and $themeconf.mobile}true{else}false{/if},
  "initRepresentative": {$initRepresentative|raw},
  "initCaddie": {if isset($ato.U_CADDIE) and isset($ato.IS_PICTURE)}{$current.id}{else}null{/if},
  "initQuickEdit": {if isset($ato.QUICK_EDIT)}{intval(isset($ato.IS_PICTURE))}{else}null{/if}
}
</script>{/footer_script}

{footer_script}<script type="module">
  import { AdminTools } from './{$ADMINTOOLS_PATH}template/public_controller.js';
  const config = JSON.parse(document.getElementById('admintools-public-config').textContent);
  AdminTools.setConfig(config);
  AdminTools.init(config.defaultOpen);
  if (config.deleteCache) AdminTools.deleteCache();
  if (config.initMobile) AdminTools.initMobile();
  if (config.initRepresentative) AdminTools.initRepresentative(config.initRepresentative.imageId, config.initRepresentative.categoryId);
  if (config.initCaddie !== null) AdminTools.initCaddie(config.initCaddie);
  if (config.initQuickEdit !== null) AdminTools.initQuickEdit(config.initQuickEdit);
</script>{/footer_script}

<div id="ato_header_closed" {if $ato.POSITION=='right'} class="right" {/if}><a href="#" class="icon-tools"></a></div>

<div id="ato_header">
  <ul>
    <li{if $ato.POSITION=='right'} class="right" {/if}><a href="#" class="icon-ato-cancel close-panel"></a></li>
      {if isset($ato.U_SITE_ADMIN)}
        <li class="parent"><a href="#" class="icon-menu ato-min-1">{'Administration'|translate}</a>
          <ul>
            <li><a class="icon-home" href="{$ato.U_SITE_ADMIN}intro">{'Home'|translate}</a></li>
            <li><a class="icon-picture" href="{$ato.U_SITE_ADMIN}batch_manager">{'Photos'|translate}</a></li>
            <li><a class="icon-sitemap" href="{$ato.U_SITE_ADMIN}albums">{'Albums'|translate}</a></li>
            <li><a class="icon-users" href="{$ato.U_SITE_ADMIN}user_list">{'Users'|translate}</a></li>
            <li><a class="icon-puzzle" href="{$ato.U_SITE_ADMIN}plugins">{'Plugins'|translate}</a></li>
            <li><a class="icon-wrench" href="{$ato.U_SITE_ADMIN}maintenance">{'Tools'|translate}</a></li>
            <li><a class="icon-cog" href="{$ato.U_SITE_ADMIN}configuration">{'Configuration'|translate}</a></li>
          </ul>
        </li>
      {/if}
      {if isset($ato.U_ADMIN_EDIT)}
        <li class="parent"><a href="#" class="icon-pencil ato-min-2">{'Edit'|translate}</a>
          <ul>
            <li><a href="#ato_quick_edit" class="icon-ato-flash edit-quick">{'Quick edit'|translate}</a></li>
            <li><a class="icon-ato-doc-text-inv" href="{$ato.U_ADMIN_EDIT}">{'Properties page'|translate}</a></li>
            {if isset($ato.U_DELETE)}
              <li style="margin-top:1em;"><a class="icon-ato-cancel" href="{$ato.U_DELETE}"
                  onclick="return confirm('{'Are you sure?'|translate|escape:javascript}')">{'delete photo'|translate|ucfirst}</a>
              </li>
            {/if}
          </ul>
        </li>
      {elseif isset($ato.QUICK_EDIT)}
        <li><a href="#ato_quick_edit" class="icon-pencil edit-quick ato-min-2">{'Edit'|translate}</a></li>
        {if isset($ato.U_DELETE)}
          <li><a class="icon-ato-cancel ato-min-2" href="{$ato.U_DELETE}"
              onclick="return confirm('{'Are you sure?'|translate|escape:javascript}')">{'delete photo'|translate|ucfirst}</a>
          </li>
        {/if}
      {/if}
      {if isset($ato.U_SET_REPRESENTATIVE)}
        <li {if $ato.IS_REPRESENTATIVE}class="disabled" {/if}><a class="icon-ato-trophy set-representative ato-min-2"
            href="{$ato.U_SET_REPRESENTATIVE}">{'representative'|translate|ucfirst}</a></li>
      {/if}
      {if isset($ato.U_CADDIE)}
        <li {if isset($ato.IS_IN_CADDIE) and $ato.IS_IN_CADDIE }class="disabled" {/if}><a
            class="icon-flag add-caddie ato-min-2" href="{$ato.U_CADDIE}">{'Add to caddie'|translate}</a></li>
      {/if}
      {if isset($ato.IS_CATEGORY)}
        <li><a class="icon-plus-circled ato-min-2"
            href="{$ato.U_SITE_ADMIN}photos_add&amp;album={$ato.CATEGORY_ID}">{'Add Photos'|translate}</a></li>
      {/if}
      <li class="saved"><span class="icon-ato-ok ato-min-1">{'Saved'|translate}</span></li>

      {if isset($ato.MULTIVIEW)}
        <li class="parent right multiview"><a class="icon-cog-alt ato-min-1" href="#">{'Tools'|translate}</a>
          <ul>
            <li><label>{'View as'|translate}</label>
              <select class="switcher" data-type="view_as"></select>
            </li>
            <li><label>{'Theme'|translate}</label>
              <select class="switcher" data-type="theme"></select>
            </li>
            <li><label>{'Language'|translate}</label>
              <select class="switcher" data-type="lang"></select>
            </li>
            <li><a class="icon-check{if !$ato.MULTIVIEW.show_queries}-empty{/if}"
                href="{$ato.U_SELF}ato_show_queries={(int)!$ato.MULTIVIEW.show_queries}">{'Show SQL queries'|translate}</a>
            </li>
            <li><a class="icon-check{if !$ato.MULTIVIEW.debug_l10n}-empty{/if}"
                href="{$ato.U_SELF}ato_debug_l10n={(int)!$ato.MULTIVIEW.debug_l10n}">{'Debug languages'|translate}</a>
            </li>
            <li><a class="icon-check{if !$ato.MULTIVIEW.debug_template}-empty{/if}"
                href="{$ato.U_SELF}ato_debug_template={(int)!$ato.MULTIVIEW.debug_template}">{'Debug template'|translate}</a>
            </li>
            <li><a class="icon-check{if !$ato.MULTIVIEW.template_combine_files}-empty{/if}"
                href="{$ato.U_SELF}ato_template_combine_files={(int)!$ato.MULTIVIEW.template_combine_files}">{'Combine JS&CSS'|translate}</a>
            </li>
            <li><a class="icon-check{if $ato.MULTIVIEW.no_history}-empty{/if}"
                href="{$ato.U_SELF}ato_no_history={(int)!$ato.MULTIVIEW.no_history}">{'Save visit in history'|translate}</a>
            </li>
            <li><a class="icon-ato-null"
                href="{$ato.U_SELF}ato_purge_template=1">{'Purge compiled templates'|translate}</a></li>
          </ul>
        </li>
        {if $ato.USER.id != $ato.MULTIVIEW.view_as}
          <li class="right ato-hide-2"><span>
              {'Viewing as <b>%s</b>.'|translate:$ato.CURRENT_USERNAME}
              <a href="{$ato.U_SELF}ato_view_as={$ato.USER.id}">{'Revert'|translate}</a>
            </span></li>
        {/if}
      {/if}
  </ul>
</div>

{if isset($ato.QUICK_EDIT)}
  <dialog id="ato_quick_edit_dlg">
    <div id="ato_quick_edit" title="{'Quick edit'|translate}">
      <form method="post" action="{$ato.U_SELF}">
        <fieldset class="left">
          {if isset($ato.QUICK_EDIT.img)}<img src="{$ato.QUICK_EDIT.img}" width="100" height="100">{/if}
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
          <input type="submit" value="{'Save'|translate}">
          <a href="#" class="icon-ato-cancel close-edit">{'Cancel'|translate}</a>
        </fieldset>

        <fieldset class="main">
          <label for="quick_edit_name">{'Name'|translate}</label>
          <input type="text" name="name" id="quick_edit_name" value="{$ato.QUICK_EDIT.name|escape:html}">

          {if isset($ato.IS_PICTURE)}
            <label for="quick_edit_author">{'Author'|translate}</label>
            <input type="text" name="author" id="quick_edit_author" {if isset($ato.QUICK_EDIT.author)}
              value="{$ato.QUICK_EDIT.author|escape:html}" {/if}>

            <label for="quick_edit_date_creation">{'Creation date'|translate}</label>
            <input type="date" name="date_creation" id="quick_edit_date_creation"
              value="{$ato.QUICK_EDIT.date_creation}">
            <input type="hidden" name="date_creation_time" value="{$ato.QUICK_EDIT.date_creation_time}">

            <label for="quick_edit_tags">{'Tags'|translate}</label>
            <select name="tags" id="quick_edit_tags" class="tags">
              {foreach from=$ato.QUICK_EDIT.tag_selection item=tag}
                <option value="{$tag.id}" class="selected">{$tag.name}</option>
              {/foreach}
            </select>

            {if isset($available_permission_levels)}
              <label for="quick_edit_level">{'Who can see this photo?'|translate}</label>
              <select name="level" size="1">
                {html_options options=$available_permission_levels selected=$ato.QUICK_EDIT.level}
              </select>
            {/if}
          {/if}

          <label for="quick_edit_comment">{'Description'|translate}</label>
          <textarea name="comment" id="quick_edit_comment">{$ato.QUICK_EDIT.comment}</textarea>
        </fieldset>

        <input type="hidden" name="action" value="quick_edit">
      </form>
    </div>
  </dialog>
{/if}