{combine_css path='node_modules/dropzone/dist/basic.css'}

{if !$DISPLAY_FORMATS}
  {include file='inc/add_album.inc.tpl'}
{/if}

{combine_css path='node_modules/tom-select/dist/css/tom-select.default.css'}

{html_style}<style>
  .addAlbumFormParent {
    display: none;
  }

  /* specific to this page, do not move in theme.css */
</style>{/html_style}

<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

{if $vite_photos_add_direct}
<script type="module" src="admin/themes/default/js/dist/{$vite_photos_add_direct}"></script>
{/if}

<div id="photosAddContent">

  {if count($setup_errors) > 0}
    <div class="errors">
      <ul>
        {foreach $setup_errors as $error}
          <li>{$error}</li>
        {/foreach}
      </ul>
    </div>
  {else}
    {if count($setup_warnings) > 0}
      <div class="warnings">
        <ul>
          {foreach $setup_warnings as $warning}
            <li>{$warning}</li>
          {/foreach}
        </ul>
        <div class="hideButton" style="text-align:center"><a href="{$hide_warnings_link}">{'Hide'|translate}</a></div>
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
          <span>{"Piwigo is also on mobile."|translate|escape:javascript}</span>
          <span>{"Try now !"|translate|escape:javascript}</span>
        </div>
        <div class="right-side">
          <div>
            <a href="{$PHPWG_URL}/mobile-applications" target="_blank"><span
                class="go-to-porg icon-link-1">{"Discover"|translate|escape:javascript}</span></a>
          </div>
        </div>
      </div>
      <span class="dont-show-again icon-cancel tiptip"
        title="{'Understood, do not show again'|translate|escape:javascript}"></span>
    </div>
  {/if}

  {if $ENABLE_FORMATS}
    <div class="format-mode-group-manager">
      <label class="switch"
        onClick="window.location.replace('{$SWITCH_MODE_URL}'); document.querySelector('.switch .slider').classList.add('loading');">
        <input type="checkbox" id="toggleFormatMode" {if $DISPLAY_FORMATS}checked{/if}>
        <span class="slider round"></span>
      </label>
      <p>{'Upload Formats'|translate}</p>
    </div>
  {/if}

  {if !$DISPLAY_FORMATS}
    <div class="addAlbumEmptyCenter" {if $NB_ALBUMS > 0} style="display:none;" {/if}>
      <div class="addAlbumEmpty">
        <div class="addAlbumEmptyTitle">{'Welcome!'|translate}</div>
        <p class="addAlbumEmptyInfos">{'Piwigo requires an album to add photos.'|translate}</p>
        <a href="#" data-add-album="category" class="buttonLike">{'Create a first album'|translate}</a>
      </div>
    </div>
  {/if}

  <div class="infos" style="display:none"><i class="eiw-icon icon-ok"></i></div>
  <div class="errors" style="display:none"><i class="eiw-icon icon-cancel"></i>
    <ul></ul>
  </div>

  <p class="afterUploadActions" style="margin:10px; display:none;">
    {if !$DISPLAY_FORMATS}
      <a class="batchLink icon-pencil"></a><span class="buttonSeparator">{'or'|translate}</span><a
        href="admin.php?page=photos_add" class="icon-plus-circled">{'Add another set of photos'|translate}</a>
    {else}
      <a href="admin.php?page=photos_add&formats" class="icon-plus-circled">{'Add another set of formats'|translate}</a>
    {/if}
  </p>

  <form id="uploadForm" class="{if $DISPLAY_FORMATS}format-mode{/if}" enctype="multipart/form-data" method="post"
    action="{$form_action}" {if $NB_ALBUMS == 0} style="display:none;" {/if}>
    {if not $DISPLAY_FORMATS}
      <fieldset class="selectAlbum">
        <legend><span class="icon-folder-open icon-red"></span>{'Drop into album'|translate}</legend>
        <div class="selectedAlbum" {if !isset($ADD_TO_ALBUM)} style="display: none" {/if}><span
            class="icon-sitemap">{if isset($ADD_TO_ALBUM)}{$ADD_TO_ALBUM}{/if}</span></div>
        <div class="selectAlbumBlock" {if isset($ADD_TO_ALBUM)} style="display: none" {/if}>
          <span id="albumSelection">
            <select data-selectize="categories" data-value="{$selected_category|json_encode|escape:html}"
              data-default="first" name="category" style="width:600px"></select>
          </span>
          <span class="orChoice">{'... or '|translate} </span>
          <a href="#" data-add-album="category" class="orCreateAlbum icon-plus-circled">
            {'create a new album'|translate}</a>
        </div>
      </fieldset>
    {elseif $HAVE_FORMATS_ORIGINAL}
      <fieldset class="originalPicture">
        <legend><span class="icon-link-1 icon-red"></span>{'Picture to associate formats with'|translate}</legend>
        <a class='info-framed' href='{$FORMATS_ORIGINAL_INFO['u_edit']}' title='{'Edit photo'|translate}'>
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
    <p class="showFieldset"><a id="showPermissions" href="#">{'Manage Permissions'|translate}</a></p>

    <fieldset id="permissions" style="display:none">
      <legend>{'Who can see these photos?'|translate}</legend>

      <select name="level" size="1">
        {html_options options=$level_options selected=$level_options_selected}
      </select>
    </fieldset>
*}
    <fieldset class="selectFiles">
      <legend><span class="icon-file-image icon-yellow"></span>{'Select files'|translate}</legend>
      <div class="selectFilesButtonBlock">
        <button id="addFiles" type="button"
          class="buttonGradient">{if not $DISPLAY_FORMATS}{'Add Photos'|translate}{else}{'Add formats'|translate}{/if}<i
            class="icon-plus-circled"></i></button>
        <div class="selectFilesinfo">
          {if isset($original_resize_maxheight)}
            <p class="uploadInfo">
              {'The picture dimensions will be reduced to %dx%d pixels.'|translate:$original_resize_maxwidth:$original_resize_maxheight}
            </p>
          {/if}
          <p id="uploadWarningsSummary">
            {if not $DISPLAY_FORMATS}
              {'Allowed file types: %s.'|translate:$upload_file_types}
            {else}
              {'Allowed file types: %s.'|translate:$str_format_ext}
              {if !$HAVE_FORMATS_ORIGINAL}
            <p>{'The original picture will be detected with the filename (without extension).'|translate}</p>{/if}
          {/if}
          </p>
          </p>
          {if isset($max_upload_resolution)}
            {'Approximate maximum resolution: %dM pixels (that\'s %dx%d pixels).'|translate:$max_upload_resolution:$max_upload_width:$max_upload_height}
          {/if}
          </p>
        </div>
      </div>
      <div id="uploader"></div>

    </fieldset>

    <div id="uploadingActions" style="display:none">
      <div class="big-progressbar" style="max-width:98%;margin-bottom: 10px;">
        <div class="progressbar" style="width:0%"></div>
      </div>
      <button id="cancelUpload" type="button" class="buttonLike icon-cancel-circled">{'Cancel'|translate}</button>
    </div>

    <button id="startUpload" type="button" class="buttonGradient icon-upload" disabled>{'Start Upload'|translate}</button>

  </form>

  <fieldset style="display:none" class="Addedphotos">
    <div id="uploadedPhotos"></div>
  </fieldset>

</div> <!-- photosAddContent -->