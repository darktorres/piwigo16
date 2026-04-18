{combine_css path="admin/themes/default/fontello/css/animation.css" order=10} {* order 10 is required, see issue 1080 *}

<script id="pwg-page-data" type="application/json">{$page_data_json}</script>

{if $vite_picture_formats}
<script type="module" src="admin/themes/default/js/dist/{$vite_picture_formats}"></script>
{/if}

<div class="formats-header">
    <a class="{if (count($FORMATS) != 0)}head-button-1{else}head-button-2{/if} icon-plus-circled"
        href="{$ADD_FORMATS_URL}">{"Add formats"|translate}</a>
</div>
<div class="formats-content">
    <div class="no-formats" {if (count($FORMATS) != 0)}style="display:none" {/if}>
        {"No format for this picture"|translate}
    </div>

    <div class="formats-list" {if (count($FORMATS) == 0)}style="display:none" {/if}>
        {foreach $FORMATS as $format}
            <div class="format-card" data-id="{$format["format_id"]}" style="background-image: url('{$IMG_SQUARE_SRC}')">
                <span class="format-card-size">{'%s MB'|translate:$format["filesize"]}</span>
                <div class="format-card-ext"><span>{$format["label"]}</span></div>
                <div class="format-card-actions">
                    <a href="{$format["download_url"]}" rel="nofollow"> <i class="icon-download"></i> </a>
                    <a class="format-delete"> <i class="icon-trash-1"></i> </a>
                </div>
            </div>
        {/foreach}
    </div>
</div>