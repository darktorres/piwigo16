{if !empty($thumbnails)}
    {if $derivative_params->type == "thumb"}
        {assign var=width value=520}
        {assign var=height value=360}
        {assign var=rwidth value=260}
        {assign var=rheight value=180}
    {else}
        {assign var=width value=$derivative_params->sizing->ideal_size[0]}
        {assign var=height value=$derivative_params->sizing->ideal_size[1]}
        {assign var=rwidth value=$width}
        {assign var=rheight value=$height}
    {/if}
    {define_derivative name='derivative_params' width=$width height=$height crop=true}
    {assign var=idx value=0+$START_ID}
    {foreach $thumbnails as $thumbnail}
        {assign var=derivative value=$pwg->derivative($derivative_params, $thumbnail.src_image)}
        {include file="grid_classes.tpl" width=$rwidth height=$rheight}
        <div class="col-outer {if isset($smarty.cookies.view) and $smarty.cookies.view == 'list'}col-12{else}{$col_class}{/if}"
            data-grid-classes="{$col_class}">
            <div
                class="card card-thumbnail {if isset($thumbnail.path_ext)}path-ext-{$thumbnail.path_ext}{/if} {if isset($thumbnail.file_ext)}file-ext-{$thumbnail.file_ext}{/if}">
                <div class="h-100">
                    <a href="{$thumbnail.URL}" data-index="{$idx}"
                        class="ripple{if isset($smarty.cookies.view) and $smarty.cookies.view != 'list'} d-block{/if}">
                        <img class="{if isset($smarty.cookies.view) and $smarty.cookies.view == 'list'}card-img-left{else}card-img-top{/if} thumb-img"
                            src="{$derivative->get_url()}" {$derivative->get_size_htm()} loading="lazy" decoding="async"
                            alt="{$thumbnail.TN_ALT}" title="{$thumbnail.TN_TITLE}">
                    </a>
                    {assign var=idx value=$idx+1}
                    {if $SHOW_THUMBNAIL_CAPTION}
                        <div
                            class="card-body{if !$theme_config->thumbnail_caption && isset($smarty.cookies.view) and $smarty.cookies.view != 'list'} d-none{/if}{if !$theme_config->thumbnail_caption} list-view-only{/if}">
                            <h6 class="card-title">
                                {if $theme_config->thumbnail_desc}
                                    {if !empty($thumbnail.DESCRIPTION)}
                                        <div id="content-description"
                                            class="py-3{if $theme_config->thumbnail_cat_desc == 'simple'} text-center{/if}">
                                            {if $theme_config->thumbnail_cat_desc == 'simple'}
                                                <h5>{$thumbnail.DESCRIPTION}</h5>
                                            {else}
                                                {$thumbnail.DESCRIPTION}
                                            {/if}
                                        </div>
                                    {/if}
                                {else}
                                    <a href="{$thumbnail.URL}"
                                        class="ellipsis{if !empty($thumbnail.icon_ts)} recent{/if}">{$thumbnail.NAME}</a>
                                {/if}
                                {if !empty($thumbnail.icon_ts)}
                                    <img title="{$thumbnail.icon_ts.TITLE}" src="{$ROOT_URL}{$themeconf.icon_dir}/recent.png" alt="(!)">
                                {/if}
                            </h6>
                            {if isset($thumbnail.NB_COMMENTS) || isset($thumbnail.NB_HITS)}
                                <div class="card-text">
                                    {if isset($thumbnail.NB_COMMENTS)}
                                        <p class="text-muted {if 0==$thumbnail.NB_COMMENTS}zero {/if}nb-comments">
                                            {$thumbnail.NB_COMMENTS|translate_dec:'%d comment':'%d comments'}
                                        </p>
                                    {/if}
                                    {if isset($thumbnail.NB_HITS)}
                                        <p class="text-muted {if 0==$thumbnail.NB_HITS}zero {/if}nb-hits">
                                            {$thumbnail.NB_HITS|translate_dec:'%d hit':'%d hits'}
                                        </p>
                                    {/if}
                                </div>
                            {/if}
                        </div>
                    {/if}
                </div>
            </div>
        </div>
    {/foreach}
{/if}