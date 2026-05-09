{combine_script id='common' load='footer' path='themes/admin/_base/js/common.js'}
{combine_script id='cat_modify' load='footer' path='themes/admin/_base/js/cat_modify.js'}
{combine_css path="themes/admin/_base/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}

<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

<div class="cat-modify" id="cat-modify">

  <div class="cat-modify-header">
    <div class="cat-modify-ariane">
    <span class="icon-home"> /</span>
      {$CATEGORIES_NAV}
    </div>

    <div class="cat-modify-actions">

      <a class="icon-pulse tiptip" href="{$U_ACTIVITY}" title="{'Activity'|translate}"></a>

      {if isset($U_MANAGE_ELEMENTS) }
        <a class="icon-th tiptip" href="{$U_MANAGE_ELEMENTS}" title="{'Manage album photos'|translate}"></a>
      {/if}

      <a class="icon-plus-circled tiptip" href="{$U_ADD_PHOTOS_ALBUM}" title="{'Add Photos'|translate}"></a>

      <a class="icon-sitemap tiptip" href="{$U_MOVE}" title="{'Manage sub-albums'|translate}"></a>

      {if isset($U_SYNC) }
        <a class="icon-exchange tiptip" href="{$U_SYNC}" title="{'Synchronize'|translate}"></a>
      {/if}

      {if isset($U_DELETE) }
        <a class="icon-trash deleteAlbum tiptip" href="#" title="{'Delete album'|translate}"></a>
      {/if} 

      {* <a class="icon-ellipsis-vert tiptip" href="#" title="{'Comments'|translate}"></a> *}

      <span class="icon-ellipsis-vert toggle-comment-option">
        <div class="comment-option">
          <span class="allow-comments icon-ok"> {'Allow comments for sub-albums'|translate} </span>
          <span class="disallow-comments icon-cancel" target="_blank">{'Disallow comments for sub-albums'|translate}</span>
        </div>
      </span>

      {* Comment for extensions to add their custom actions *}
    </div>
  </div>

  <div class="cat-modify-content">

    <div class="cat-modify-infos">
      <div class="cat-modify-info-card cat-creation">
        <span class="cat-modify-info-title">{'Created'|translate}</span>
        <span class="cat-modify-info-content">{if isset($INFO_CREATION_SINCE)}{$INFO_CREATION_SINCE}{else}{'unknown'|translate}{/if}</span>
        <span class="cat-modify-info-subcontent">{if isset($INFO_CREATION)}{$INFO_CREATION}{else}{'Unknown time period'|translate}{/if}</span>
      </div>
      <div class="cat-modify-info-card cat-modification">
        <span class="cat-modify-info-title">{'Modified'|translate}</span>
        <span class="cat-modify-info-content">{$INFO_LAST_MODIFIED_SINCE}</span>
        <span class="cat-modify-info-subcontent">{$INFO_LAST_MODIFIED}</span>
      </div>
      <div title="{$INFO_TITLE}" class="cat-modify-info-card cat-photos">
        <span class="cat-modify-info-title">{'Photos'|translate}</span>
        <span class="cat-modify-info-content">{$INFO_PHOTO}</span>
        <span class="cat-modify-info-subcontent">{$INFO_IMAGES_RECURSIVE}</span>
      </div>
      <div class="cat-modify-info-card cat-albums">
        <span class="cat-modify-info-title">{'sub-albums'|translate}</span>
        <span class="cat-modify-info-content">{$INFO_DIRECT_SUB}</span>
        <span class="cat-modify-info-subcontent">{$INFO_SUBCATS}</span>
      </div>
      {if isset($U_SYNC) }
      <div class="cat-modify-info-card">
        <span class="cat-modify-info-title">{'Directory'}</span>
        <span class="cat-modify-info-content directory" title="{$CAT_DIR_NAME}">{$CAT_DIR_NAME}</span>
        <span class="cat-modify-info-subcontent directory" title="{$CAT_FULL_DIR}">{$CAT_MIN_DIR}</span>
      </div>
      {/if}
    </div>

    <div 
      class="cat-modify-representative {if !isset($representant)}icon-file-image{elseif !isset($representant.picture)}icon-dice-solid{/if}" 
      {if !isset($representant)}title="{'No photos in the current album, no thumbnail available'|translate}"{/if} 
      {if isset($representant) && isset($representant.picture)}style="--bg-image:url('{$representant.picture.src}')"{/if}
      >
      {if isset($representant) and ($representant.ALLOW_SET_RANDOM || $representant.ALLOW_SET_RANDOM)}
      <div class="cat-modify-representative-actions">
        {if $representant.ALLOW_SET_RANDOM }
          <a class="refreshRepresentative buttonLike" id="refreshRepresentative" title="{'Find a new representant by random'|translate}">
            <i class="icon-ccw"></i>
            {'Refresh thumbnail'|translate}
          </a>
        {/if}
        {if isset($representant.ALLOW_DELETE)}
          <a class="deleteRepresentative buttonLike" id="deleteRepresentative" title="{'Delete Representant'|translate}" {if !isset($representant.picture)}hidden{/if}>
            <i class="icon-cancel"></i>
            {'Remove thumbnail'|translate}
          </a>
        {/if}
      </div>
      {/if}
    </div>

    <div class="cat-modify-form">
      <div class="cat-modify-input-container">
        <label for="cat-name">{'Name'|translate}</label>
        <input type="text" id="cat-name" value="{$CAT_NAME}" maxlength="255">
      </div>

      <div class="cat-modify-input-container">
        <label for="cat-comment">{'Description'|translate} <span id="desc-zoom-square" class="icon-resize-full tiptip" title="{'Expand'|translate}"></span></label>
        <textarea class="sync-textarea" resize="false" rows="5" name="comment" id="cat-comment">{$CAT_COMMENT}</textarea>
      </div>

      <div class="cat-modify-input-container">
        <label for="cat-parent">{'Parent album'|translate}</label>
        <div class="icon-pencil" id="cat-parent">{$CATEGORIES_PARENT_NAV}</div>
      </div>

      {include file='include/album_selector.inc.tpl'}

      {if isset($CAT_COMMENTABLE)}
      <div class="cat-modify-switch-container">
        <div class="switch-input">
          <label class="switch">
            <input type="checkbox" name="commentable" id="cat-commentable" value="true" {if $CAT_COMMENTABLE == "true"}checked{/if}>
            <span class="slider round"></span>
          </label>
        </div>
        <label class="switch-label" for="cat-commentable"><span>{'Authorize comments'|translate}</span> <i class="icon-help-circled tiptip" title="{'A photo can receive comments from your visitors if it belongs to an album with comments activated.'|translate}"></i></label>
      </div>
      {/if}

      <div class="cat-modify-switch-container">
        <div class="switch-input">
          <label class="switch">
            <input type="checkbox" name="locked" id="cat-locked" value="true" {if $IS_VISIBLE == 'false'}checked{/if}>
            <span class="slider round"></span>
          </label>
          
        </div>    
        <label class="switch-label" for="cat-locked"><span>{'Locked album'|translate}</span> <i class="icon-help-circled tiptip" title="{'Locked albums are disabled for maintenance. Only administrators can view them in the gallery. Lock this album will also lock his Sub-albums'|translate}"></i></label>
      </div>
    </div>
  </div>

  <div class="cat-modify-footer">
   <div class="cat-modify-footer-start">
    {if $CAT_ADMIN_ACCESS}
      <a class="cat-modify-footer-see-out" href="{$U_JUMPTO}"><i class="icon-left-open"></i>{'Open in gallery'|translate}</a>
    {else}
    <a class="tiptip cat-modify-footer-see-out disabled" title="{'ACCESS_5'|translate}" href="#"><i class="icon-left-open"></i>{'Open in gallery'|translate}</a>
    {/if}
   </div>
   <div class="cat-modify-footer-end">
    <div class="info-message icon-ok">{'Album updated'|translate}</div>
    <div class="info-error icon-cancel">{'An error has occured while saving album settings'|translate}</div>
    <span class="buttonLike" id="cat-properties-save"><i class="icon-floppy"></i> {'Save Settings'|translate}</span>
   </div>
  </div>
  <div class="desc-modal" id="desc-modal">
    <div class="desc-modal-content">
      <div class="desc-modal-header">
        <p>{'Description'|translate}</p>
      </div>
      <div class="desc-modal-body">
        <textarea class="sync-textarea" name="comment-modal" id="cat-comment-modal">{$CAT_COMMENT}</textarea>
        </div>
      <div class="desc-modal-footer">
        <p id="desc-modal-close" class="cat-modify-footer-see-out"><span class="icon-resize-small"></span>{'Shrink'|translate}</p>
      </div>
    </div>
  </div>
</div>

{combine_css path="themes/admin/_base/css/pages/cat-modify.css"}