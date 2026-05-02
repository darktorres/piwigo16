<script id="pwg-page-data" type="application/json">{$page_data_json}</script>
{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_script id='site_manager' load='footer' require='common' path='admin/themes/default/js/site_manager.js'}
{combine_css path="admin/themes/default/css/pages/site_manager.css"}

{if not empty($remote_output)}
<div class="remoteOutput">
  <ul>
    {foreach from=$remote_output item=remote_line}
    <li class="{$remote_line.CLASS}">{$remote_line.CONTENT}</li>
    {/foreach}
  </ul>
</div>
{/if}

{if not empty($sites)}
<table class="table2 site-manager-table">
	<tr class="throw">
		<td>{'Directory'|@translate}</td>
		<td>{'Actions'|@translate}</td>
	</tr>
  {foreach from=$sites item=site name=site}
  <tr class="{if $smarty.foreach.site.index is odd}row1{else}row2{/if}"><td>
    <a href="{$site.NAME}">{$site.NAME}</a><br>({$site.TYPE}, {$site.CATEGORIES} {'Albums'|@translate}, {$site.IMAGES|@translate_dec:'%d photo':'%d photos'})
  </td><td>
    [<a href="{$site.U_SYNCHRONIZE}" title="{'update the database from files'|@translate}">{'Synchronize'|@translate}</a>]
    {if isset($site.U_DELETE)}
      [<a class="delete-site-button" href="{$site.U_DELETE}" title="{'delete this site and all its attached elements'|@translate}">{'delete'|@translate}</a>]
    {/if}
    {if not empty($site.plugin_links)}
        <br>
      {foreach from=$site.plugin_links item=plugin_link}
        [<a href="{$plugin_link.U_HREF}" title='{$plugin_link.U_HINT}'>{$plugin_link.U_CAPTION}</a>]
      {/foreach}
    {/if}
  </td></tr>
  {/foreach}
</table>
{/if}

<p id="showCreateSite">
  <a href="#">{'create a new site'|@translate}</a>
</p>

<form action="{$F_ACTION}" method="post" id="createSite" hidden>
  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
  <fieldset>
    <legend>{'create a new site'|@translate}</legend>

  <p><strong>{'Directory'|@translate}</strong>
    <br><input type="text" name="galleries_url" id="galleries_url">
  </p>

  <p class="actionButtons">
    <input class="submit" type="submit" name="submit" value="{'Submit'|@translate}">
  </p>
</form>
