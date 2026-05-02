<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{* tree.css is now bundled into albums.js via the album-tree module *}
{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}

{combine_script id='cat_search' load='footer' path='admin/themes/default/js/cat_search.js'}
{combine_script id='albums' load='footer' path='admin/themes/default/js/albums.js'}

<div class="cat-move-order-popin">
  <div class="order-popin-container">
    <a class="close-popin icon-cancel" onClick="var el=document.querySelector('.cat-move-order-popin');if(el)el.style.display='none'"> </a>
    <div class="popin-title"><span class="icon-sort-name-up icon-purple"></span><span class="popin-title-text">{'apply automatic sort order'|translate}</span></div>
    <div class="album-name icon-sitemap"></div>
    <form action="{$F_ACTION}" method="post">
      <input type="hidden" name="id" value="-1">
      <div class="choice-container">
        <label class="font-checkbox">
          <span class="icon-dot-circled"> </span>
          <input type="radio" value="name ASC" name="order" checked>
          {'Album name, A &rarr; Z'|@translate}
        </label>

        <label class="font-checkbox">
          <span class="icon-dot-circled"> </span>
          <input type="radio" value="name DESC" name="order">
          {'Album name, Z &rarr; A'|@translate}
        </label>

        <label class="font-checkbox">
          <span class="icon-dot-circled"> </span>
          <input type="radio" value="natural_order ASC" name="order">
          {'Album name, 1 &rarr; 5 &rarr; 10 &rarr; 100'|@translate}
        </label>
        
        <label class="font-checkbox">
          <span class="icon-dot-circled"> </span>
          <input type="radio" value="natural_order DESC" name="order">
          {'Album name, 100 &rarr; 10 &rarr; 5 &rarr; 1'|@translate}
        </label>
        
        <label class="font-checkbox">
          <span class="icon-dot-circled"> </span>
          <input type="radio" value="date_creation DESC" name="order">
          {'Date created, new &rarr; old'|@translate}
        </label>

        <label class="font-checkbox">
          <span class="icon-dot-circled"> </span>
          <input type="radio" value="date_creation ASC" name="order">
          {'Date created, old &rarr; new'|@translate}
        </label>

        <label class="font-checkbox">
          <span class="icon-dot-circled"> </span>
          <input type="radio" value="date_available DESC" name="order">
          {'Date posted, new &rarr; old'|@translate}
        </label>

        <label class="font-checkbox">
          <span class="icon-dot-circled"> </span>
          <input type="radio" value="date_available ASC" name="order">
          {'Date posted, old &rarr; new'|@translate}
        </label>
      </div>
      <input type="submit" name="simpleAutoOrder" value="{'Apply to direct sub-albums'|@translate}"/>
      <input type="submit" name="recursiveAutoOrder" value="{'Apply to the whole hierarchy'|@translate}"/>
    </form>
  </div>
</div>

<div class="cat-move-header"> 
  <div class="add-album-button">
    <label class="head-button-2 icon-add-album">
      <p>{'Add Album'|@translate}</p>
    </label>
  </div>
  <div class="order-root-button">
    <label class="order-root head-button-2 icon-sort-name-up">
      <p>{'Automatic sort order'|@translate}</p>
    </label>
  </div>
  {* <div class="cat-move-info icon-help-circled"> {'Drag and drop to reorder albums'|@translate}</div> *}
  <div class="cat-move-info search-album">
    <div class="search-album-cont">
      {* <div class="search-album-label">{'Search albums'|@translate}</div> *}
      <span class="search-album-num-result"></span>
      <div class="search-album-input-container" style="position:relative">
        <span class="icon-search search-icon"></span>
        <span class="icon-cancel search-cancel"></span>
        <input id="cat_search_input" class='search-input' type="text" placeholder="{"Search"|@translate}">
      </div>
      <span class="search-album-help icon-help-circled" title="{'Enter a term to search for album'|@translate}"></span>
    </div>
  </div>
</div>

<div id="AddAlbum" class="AddAlbumPopIn">
  <div class="AddAlbumPopInContainer">
    <a class="icon-cancel CloseAddAlbum"></a>
    
    <div class="AddIconContainer">
      <span class="AddIcon icon-blue icon-add-album"></span>
    </div>
    <div class="AddIconTitle">
      <span></span>
    </div>

    <div class="AddAlbumInputContainer">
      <label class="user-property-label AddAlbumLabelUsername">{'Album name'|@translate}
        <input class="user-property-input" />
      </label>
    </div>

    <div class="AddAlbumInputContainer">
      <label class="user-property-label AddAlbumLabelUsername">{'Position'|@translate}

      <div class="AddAlbumPositionSelect">
        <div class="AddAlbumRadioInput">
          <input type="radio" id="place-start"
          name="position" value="first" {if "first" == {$POS_PREF}} checked {/if}>
          <label for="place-start">{'Place first'|translate}</label>
        </div>
        <div class="AddAlbumRadioInput">
          <input type="radio" id="place-end"
          name="position" value="last" {if "last" == {$POS_PREF}} checked {/if}>
          <label for="place-end">{'Place last'|translate}</label>
        </div>
      </div>
    </div>
    

    <div class="AddAlbumErrors icon-cancel">
    </div>

    <div class="AddAlbumFormValidation">
      <div class="AddAlbumSubmit">
        <span>{'Add'|@translate}</span>
      </div>

      <div class="AddAlbumCancel">
        <span>{'Cancel'|@translate}</span>
      </div>
    </div>
  </div>
</div>

<div id="RenameAlbum" class="RenameAlbumPopIn">
  <div class="RenameAlbumPopInContainer">
    <a class="icon-cancel CloseRenameAlbum"></a>
    
    <div class="AddIconContainer">
      <span class="AddIcon icon-blue icon-pencil"></span>
    </div>
    <div class="RenameAlbumTitle">
      <span></span>
    </div>

    <div class="RenameAlbumInputContainer">
      <label class="user-property-label RenameAlbumLabelUsername">{'Rename album'|@translate}
        <input class="user-property-input" />
      </label>
    </div>

    <div class="RenameAlbumErrors icon-cancel">
    </div>

    <div class="RenameAlbumFormValidation">
      <div class="RenameAlbumSubmit">
        <span>{'Yes, rename'|@translate}</span>
      </div>

      <div class="RenameAlbumCancel">
        <span>{'Cancel'|@translate}</span>
      </div>
    </div>
  </div>
</div>

<div id="DeleteAlbum" class="DeleteAlbumPopIn">
  <div class="DeleteAlbumPopInContainer">
    <div class="DeleteIconTitle">
      <span>{'Supprimer l\'album : tatatatattata'|translate}</span>
    </div>

    <div class="DeleteAlbumInputContainer">
      <ul class="deleteAlbumOptions">
        <li id="IMAGES_ASSOCIATED_OUTSIDE"><label class=""><input type="radio" name="photo_deletion_mode" value="force_delete"><span class="innerText"></span></label></li>
        <li id="IMAGES_BECOMING_ORPHAN"><label class=""><input type="radio" name="photo_deletion_mode" value="delete_orphans"><span class="innerText"></span></label></li>
        <li id="IMAGES_RECURSIVE"><label class=""><input type="radio" name="photo_deletion_mode" value="no_delete" checked="checked">{'delete only album, not photos'|translate}</label></li>
      </ul>
    </div>
    

    <div class="DeleteAlbumErrors icon-cancel">
    </div>

    <div class="DeleteAlbumFormValidation">
      <div class="DeleteAlbumSubmit">
        <span>{'Confirm deletion'|translate}</span>
      </div>

      <div class="DeleteAlbumCancel">
        <span>{'Cancel'|translate}</span>
      </div>
    </div>
  </div>
</div>

<div class='tree'> </div>

<div class="album-search-result-container" hidden>
  <div class="search-album-result"></div>
  <div class="search-album-elem limit-album-reached" hidden></div>

  <div class="search-album-noresult">
    {'No albums found'|translate}
  </div>
</div>

<div class="search-album-elem-template" hidden>
  <div class="search-album-elem" hidden>
    <span class='search-album-icon'></span>
    <p class='search-album-name'></p>
    <div class="search-album-action-cont">
      <div class="search-album-action">
        <a class="icon-pencil search-album-edit">{'Edit album'|translate}</a>
      </div>
    </div>
  </div>
</div>
{combine_css path="admin/themes/default/css/pages/albums.css"}