{if empty($load_mode)}{$load_mode='footer'}{/if}
{if !isset($show_root_btn)}{$show_root_btn=false}{/if}

<script id="pwg-album-selector-data" type="application/json">
{
  "apiMethod": "{if isset($api_method)}{$api_method|escape:'html'}{else}pwg.categories.getAdminList{/if}",
  "strNoSearchInProgress": "{'No search in progress'|translate|escape:'html'}",
  "strAlbumsFound": "{'<b>%d</b> albums found'|translate|escape:'html'}",
  "strAlbumFound": "{'<b>1</b> album found'|translate|escape:'html'}",
  "strResultLimit": "{if isset($str_result_limit)}{$str_result_limit|escape:'html'}{else}{'<b>%d</b> result limit reached'|translate|escape:'html'}{/if}"
}
</script>

{if $vite_album_selector}
<script type="module" src="admin/themes/default/js/dist/{$vite_album_selector}"></script>
{/if}

<div id="addLinkedAlbum" class="linkedAlbumPopIn">
  <div class="linkedAlbumPopInContainer">
    <a class="icon-cancel ClosePopIn"></a>

    <div class="AddIconContainer">
      <span class="AddIcon icon-blue icon-plus-circled"></span>
    </div>
    <div class="AddIconTitle">
      <span>{$title}</span>
    </div>

    {if $show_root_btn}
      <label class="head-button-2 put-to-root">
        <p class="icon-home">{'Put at the root'|translate}</p>
      </label>
      <p>{'or'|translate}</p>
    {/if}

    <div id="linkedAlbumSearch">
      <span class='icon-search search-icon'> </span>
      <span class="icon-cancel search-cancel-linked-album"></span>
      <input class='search-input' type='text' placeholder={$searchPlaceholder}>
    </div>
    <div class="limitReached"></div>
    <div class="noSearch"></div>
    <div class="searching icon-spin6 animate-spin"> </div>

    <div id="searchResult">
    </div>
  </div>
</div>