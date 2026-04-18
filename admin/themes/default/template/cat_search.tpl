{footer_script}<script>
  window.cat_search_nb_cats = {$nb_cats};
  window.cat_search_data = {json_encode($data_cat)};
  window.cat_search_str_albums_found = '{"<b>%d</b> albums found"|translate}';
  window.cat_search_str_album_found = '{"<b>1</b> album found"|translate}';
  window.cat_search_str_result_limit = '{"<b>%d+</b> albums found, try to refine the search"|translate|escape:javascript}';
</script>{/footer_script}

{if $vite_cat_search}
<script type="module" src="admin/themes/default/js/dist/{$vite_cat_search}"></script>
{/if}

<div class="search-album">
  <div class="search-album-cont">
    <div class="search-album-label">{'Search albums'|translate}</div>
    <div class="search-album-input-container" style="position:relative">
      <span class="icon-search search-icon"></span>
      <span class="icon-cancel search-cancel"></span>
      <input class='search-input' type="text" placeholder="{$placeholder|escape:html}">
    </div>
    <span class="search-album-help icon-help-circled" title="{'Enter a term to search for album'|translate}"></span>
    <span class="search-album-num-result"></span>
  </div>
</div>

<div class="search-album-ghost">
  <span>{'No research in progress'|translate}</span>
</div>

<div class="search-album-elem-template" style="display:none">
  <div class="search-album-elem" style="display:none">
    <span class='search-album-icon'></span>
    <p class='search-album-name'></p>
    <div class="search-album-action-cont">
      <div class="search-album-action">
        <a class="icon-pencil search-album-edit">{'Edit album'|translate}</a>
      </div>
    </div>
  </div>
</div>

<div class="search-album-result">

</div>
<div class="search-album-elem limit-album-reached"></div>

<div class="search-album-noresult">
  {'No albums found'|translate}
</div>

<style>
  .limit-album-reached {
    display: flex;
    justify-content: center;
    align-items: center;
  }
</style>