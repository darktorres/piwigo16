{if isset($ADD_TO_ALBUM) or isset($selected_category_name)}{$can_upload=true}{else}{$can_upload=false}{/if}

{combine_script id='common' load='footer' path='themes/admin/default/js/common.js'}
{include file='include/colorbox.inc.tpl'}
{if !$DISPLAY_FORMATS}
  {include file='include/add_album.inc.tpl'}
{/if}

{combine_script id='add_photo' load='footer' path='themes/admin/default/js/photos_add_direct.js'}

{combine_css path="themes/admin/default/css/pages/photos_add_direct.css"}

<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

<div id="photosAddContent">

{if count($setup_errors) > 0}
  <div class="errors">
    <ul>
    {foreach from=$setup_errors item=error}
      <li>{$error}</li>
    {/foreach}
    </ul>
  </div>
  {else}
    {if count($setup_warnings) > 0}
  <div class="warnings">
    <ul>
      {foreach from=$setup_warnings item=warning}
      <li>{$warning}</li>
      {/foreach}
    </ul>
    <div class="hideButton u-text-center"><a href="{$hide_warnings_link}">{'Hide'|@translate}</a></div>
  </div>
    {/if}
  {/if} {* $setup_errors *}
  
  {if $PROMOTE_MOBILE_APPS}
    <div class="promote-apps">
      <div class="promote-content">
        <div class="left-side">
          <img src="https://sandbox.piwigo.com/uploads/4/y/1/4y1zzhnrnw//2023/01/24/20230124175152-015bc1e3.png">
        </div>
        <div class="promote-text">
          <span>{"Piwigo is also on mobile."|@translate|escape:javascript}</span>
          <span>{"Try now !"|@translate|escape:javascript}</span>
        </div>
        <div class="right-side">
          <div>
            <a href="{$PHPWG_URL}/mobile-applications" target="_blank"><span class="go-to-porg icon-link-1">{"Discover"|@translate|escape:javascript}</span></a>
          </div>
        </div>
      </div>
      <span class="dont-show-again icon-cancel tiptip" title="{'Understood, do not show again'|translate|escape:javascript}"></span>
    </div>
  {/if}

  {if $ENABLE_FORMATS and $can_upload}
    <div class="format-mode-group-manager">
    <label class="switch" onClick="window.location.replace('{$SWITCH_FORMAT_MODE_URL}'); $('.switch .slider').addClass('loading');">
      <input type="checkbox" id="toggleFormatMode" {if $DISPLAY_FORMATS}checked{/if}>
      <span class="slider round"></span>
    </label>
      <p>{'Upload Formats'|@translate}</p>
    </div>
  {/if}

  {if !$DISPLAY_FORMATS}
  <div class="addAlbumEmptyCenter"{if $NB_ALBUMS > 0} hidden{/if}>
    <div class="addAlbumEmpty">
      <div class="addAlbumEmptyTitle">{'Welcome!'|translate}</div>
      <p class="addAlbumEmptyInfos">{'Piwigo requires an album to add photos.'|translate}</p>
      <a class="buttonLike" id="btnFirstAlbum">{'Create a first album'|translate}</a>
    </div>
  </div>
  {/if}

<div class="infos" hidden><i class="eiw-icon icon-ok"></i></div>
<div class="errors" hidden><i class="eiw-icon icon-cancel"></i><ul></ul></div>

<p class="afterUploadActions" hidden>
  {if !$DISPLAY_FORMATS}
    <a class="batchLink icon-pencil"></a><span class="buttonSeparator">{'or'|translate}</span><a href="{$ADMIN_URL}&amp;page=photos_add" class="secondary_button icon-plus-circled">{'Add another set of photos'|@translate}</a>
  {else}
    <a href="{$ADMIN_URL}&amp;page=photos_add&amp;formats" class="icon-plus-circled">{'Add another set of formats'|@translate}</a>
  {/if}
</p>

  <form id="uploadForm" class="{if $DISPLAY_FORMATS}format-mode{/if}" enctype="multipart/form-data" method="post" action="{$form_action}"{if $NB_ALBUMS == 0} hidden{/if}>
    {if not $DISPLAY_FORMATS}
    <fieldset class="selectAlbum">
      <legend><span class="icon-folder-open icon-red"></span>{'Drop into album'|@translate}</legend>
      <div class="selectedAlbum"{if !$can_upload} hidden{/if} id="selectedAlbum">
        <span class="icon-sitemap" id="selectedAlbumName">{if isset($ADD_TO_ALBUM)}{$ADD_TO_ALBUM}{elseif isset($selected_category_name)}{$selected_category_name}{/if}</span>
        <a class="icon-pencil" id="selectedAlbumEdit"></a>
      </div>
      <div class="selectAlbumSelector" {if $can_upload} hidden{/if} id="addPhotosAS">
        <p class="head-button-1 icon-folder-open" id="btnPhotosAS">{"Select or create an album"|translate}</p>
      </div>
    </fieldset>
    {elseif $HAVE_FORMATS_ORIGINAL}
    <fieldset class="originalPicture">
      <legend><span class="icon-link-1 icon-red"></span>{'Picture to associate formats with'|@translate}</legend>
      <a class='info-framed' href='{$FORMATS_ORIGINAL_INFO['u_edit']}' title='{'Edit photo'|@translate}'>
        <div class='info-framed-icon'>
          <img src='{$FORMATS_ORIGINAL_INFO['src']}'></i>
        </div>
        <div class='info-framed-container'>
          <div class='info-framed-title'>{$FORMATS_ORIGINAL_INFO['name']}</div>
          {if isset($FORMATS_ORIGINAL_INFO['formats'])}<div>{$FORMATS_ORIGINAL_INFO['formats']}</div>{/if}
          <div>{$FORMATS_ORIGINAL_INFO.ext}</div>
        </div>
      </a>
    </fieldset>
    {/if}
{*
    <p class="showFieldset"><a id="showPermissions" href="#">{'Manage Permissions'|@translate}</a></p>

    <fieldset id="permissions" hidden>
      <legend>{'Who can see these photos?'|@translate}</legend>

      <select name="level" size="1">
        {html_options options=$level_options selected=$level_options_selected}
      </select>
    </fieldset>
*}
    <fieldset class="selectFiles">

      <legend>
        <div>
          <span class="icon-file-image icon-yellow"></span>{'Select files'|@translate}
          {if !$DISPLAY_FORMATS}
          <div id="uploadOptions" class="upload-options">
            <span class="icon-equalizer rotate-element upload-options-icon"></span>{'Options'|@translate}
          </div>
          {/if}
        </div>
      {if !$DISPLAY_FORMATS}
      <div class="upload-options-content" id="uploadOptionsContent">
        <label class="switch">
          <input type="checkbox" id="toggleUpdateMode">
          <span class="slider round"></span>
        </label>
        <div>
          <p>{'If a photo in this album has the same filename, update the file without changing the photo\'s properties'|@translate}</p>
        </div>
      </div>
      {/if}
      </legend>

      <div class="selectFilesButtonBlock">
        <button id="addFiles" class="buttonLike icon-plus-circled" {if !$can_upload}disabled{/if}>
          {if not $DISPLAY_FORMATS}{'Add Photos'|translate}{else}{'Add formats'|@translate}{/if}
        </button>
        <div class="selectFilesinfo">
          {if isset($original_resize_maxheight)}
          <p class="uploadInfo">{'The picture dimensions will be reduced to %dx%d pixels.'|@translate:$original_resize_maxwidth:$original_resize_maxheight}</p>
          {/if}
            <p id="uploadWarningsSummary">
            {if not $DISPLAY_FORMATS}
              {'Allowed file types: %s.'|@translate:$upload_file_types}
            {else}
              {'Allowed file types: %s.'|@translate:$str_format_ext} 
              {if !$HAVE_FORMATS_ORIGINAL}<p>{'The original picture will be detected with the filename (without extension).'|@translate}</p>{/if}
            {/if}
            </p>
          </p>
            {if isset($max_upload_resolution)}
            {'Approximate maximum resolution: %dM pixels (that\'s %dx%d pixels).'|@translate:$max_upload_resolution:$max_upload_width:$max_upload_height}
            {/if}
          </p>
        </div>
      </div>
      <div class="photosUploader" id="uploader" {if !$can_upload}hidden{/if}>
        <p>Your browser doesn't have HTML5 support.</p>
      </div>
      <div class="selectAlbumFirst" id="chooseAlbumFirst" {if $can_upload}hidden{/if}>
        <p>{"First choose an album, then add your files"|translate}</p>
      </div>
    </fieldset>
    
    <div id="uploadingActions" hidden>
      <div class="big-progressbar">
        <div class="progressbar"></div>
      </div>
      <button id="cancelUpload" class="buttonLike icon-cancel-circled">{'Cancel'|translate}</button>
    </div>

    <button id="startUpload" class="buttonLike icon-upload" disabled>{'Start Upload'|translate}</button>

  </form>

  <fieldset hidden class="Addedphotos">
    <div id="uploadedPhotos"></div>
  </fieldset>

</div> <!-- photosAddContent -->
<div class="bg-modal" id="addFirstAlbum">
  <div class="new-album-modal-content">
     <a class="icon-cancel close-modal" id="closeFirstAlbum"></a>

    <div class="AddIconContainer">
     <span class="AddIcon icon-blue icon-add-album"></span>
    </div>
    <div class="AddIconTitle">
      <span>{'Create your first album'|translate}</span>
    </div>
    <div class="AddAlbumInputContainer">
      <label class="user-property-label AddAlbumLabelUsername">{'Album name'|translate}
        <input class="user-property-input" id="inputFirstAlbum">
      </label>
    </div>
    <a class="buttonLike icon-plus" id="btnAddFirstAlbum">{'Create and select'|translate}</a>

  </div>
</div>

{include file='include/album_selector.inc.tpl'}