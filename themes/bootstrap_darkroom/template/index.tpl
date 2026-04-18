<!-- Start of index.tpl -->
{if !empty($PLUGIN_INDEX_CONTENT_BEFORE)}{$PLUGIN_INDEX_CONTENT_BEFORE}{/if}

<nav
    class="navbar navbar-expand-lg navbar-contextual {$theme_config->navbar_contextual_style} {$theme_config->navbar_contextual_bg}{if $theme_config->page_header == 'fancy' && $theme_config->page_header_both_navs} navbar-transparent navbar-sm{/if} sticky-top mb-2">
    <div class="container{if $theme_config->fluid_width}-fluid{/if}">
        <div class="navbar-brand me-auto">
            {if isset($chronology.TITLE)}
                <a href="{$U_HOME}" title="{'Home'|translate}"><i class="fas fa-home"
                        aria-hidden="true"></i></a>{$LEVEL_SEPARATOR}{$chronology.TITLE}
            {else}
                <div class="nav-breadcrumb d-inline-flex">{$TITLE}</div>
            {/if}
        </div>
        <button type="button" class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#secondary-navbar"
            aria-controls="secondary-navbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="fas fa-bars"></span>
        </button>
        <div class="navbar-collapse collapse justify-content-end" id="secondary-navbar">
            <ul class="navbar-nav">
                {if isset($SEARCH_IN_SET_ACTION) and $SEARCH_IN_SET_ACTION}
                    <li id="cmdSearchInSet" class="nav-item">
                        <a href="{$SEARCH_IN_SET_URL}" title="{'Search in this set'|translate}"
                            class="pwg-state-default pwg-button nav-link">
                            <i class="fas fa-search"></i>
                            <span class="pwg-button-text">{'Search in this set'|translate}</span>
                        </a>
                    </li>
                {/if}
                {if !empty($image_orders)}
                    <li class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown"
                            title="{'Sort order'|translate}">
                            <i class="fas fa-sort fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'Sort order'|translate}</span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end" role="menu">
                            {foreach $image_orders as $image_order}
                                <a class="dropdown-item{if $image_order.SELECTED} active{/if}" href="{$image_order.URL}"
                                    rel="nofollow">{$image_order.DISPLAY}</a>
                            {/foreach}
                        </div>
                    </li>
                {/if}
                {if !empty($image_derivatives)}
                    <li class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown"
                            title="{'Photo sizes'|translate}">
                            <i class="far fa-image fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'Photo sizes'|translate}</span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end" role="menu">
                            {foreach $image_derivatives as $image_derivative}
                                <a class="dropdown-item{if $image_derivative.SELECTED} active{/if}"
                                    href="{$image_derivative.URL}" rel="nofollow">{$image_derivative.DISPLAY}</a>
                            {/foreach}
                        </div>
                    </li>
                {/if}
                {if isset($favorite)}
                    <li class="nav-item">
                        <a class="nav-link" href="{$favorite.U_FAVORITE}"
                            title="{'Delete all photos from your favorites'|translate}" rel="nofollow">
                            <i class="fas fa-heartbeat fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'Delete all photos from your favorites'|translate}</span>
                        </a>
                    </li>
                {/if}
                {if isset($U_EDIT)}
                    <li class="nav-item">
                        <a class="nav-link" href="{$U_EDIT}" title="{'Edit album'|translate}">
                            <i class="fas fa-pencil-alt fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'Edit album'|translate}</span>
                        </a>
                    </li>
                {/if}
                {if isset($U_CADDIE)}
                    <li class="nav-item">
                        <a class="nav-link" href="{$U_CADDIE}" title="{'Add to caddie'|translate}">
                            <i class="fas fa-shopping-basket fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'Add to caddie'|translate}</span>
                        </a>
                    </li>
                {/if}
                {if isset($U_SEARCH_RULES)}
                    {combine_script id='core.scripts' load='async' path='themes/default/js/scripts.js'}
                    <li class="nav-item">
                        <a class="nav-link" href="{$U_SEARCH_RULES}" onclick="bd_popup(this.href); return false;"
                            title="{'Search rules'|translate}" rel="nofollow">
                            <i class="fas fa-search fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'Search rules'|translate}</span>
                        </a>
                    </li>
                {/if}
                {if isset($U_SLIDESHOW)}
                    <li class="nav-item">
                        <a class="nav-link" href="{$U_SLIDESHOW}"
                            id="startSlideshow" title="{'slideshow'|translate}" rel="nofollow">
                            <i class="fas fa-play fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2 text-capitalize">{'slideshow'|translate}</span>
                        </a>
                    </li>
                {/if}
                {if isset($U_MODE_FLAT)}
                    <li class="nav-item">
                        <a class="nav-link" href="{$U_MODE_FLAT}"
                            title="{'display all photos in all sub-albums'|translate}" rel="nofollow">
                            <i class="fas fa-th-large fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'display all photos in all sub-albums'|translate}</span>
                        </a>
                    </li>
                {/if}
                {if isset($U_MODE_NORMAL)}
                    <li class="nav-item">
                        <a class="nav-link" href="{$U_MODE_NORMAL}" title="{'return to normal view mode'|translate}">
                            <i class="fas fa-home fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'return to normal view mode'|translate}</span>
                        </a>
                    </li>
                {/if}
                {if isset($U_MODE_POSTED) || isset($U_MODE_CREATED)}
                    <li class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" title="{'Calendar'|translate}">
                            <i class="far fa-calendar-alt fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'Calendar'|translate}</span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end">
                            {if isset($U_MODE_POSTED)}
                                <a class="dropdown-item" href="{$U_MODE_POSTED}"
                                    title="{'display a calendar by posted date'|translate}" rel="nofollow">
                                    <i class="fas fa-share-alt fa-fw" aria-hidden="true"></i>
                                    {'display a calendar by posted date'|translate}
                                </a>
                            {/if}
                            {if isset($U_MODE_CREATED)}
                                <a class="dropdown-item" href="{$U_MODE_CREATED}"
                                    title="{'display a calendar by creation date'|translate}" rel="nofollow">
                                    <i class="fas fa-camera-retro fa-fw" aria-hidden="true"></i>
                                    {'display a calendar by creation date'|translate}
                                </a>
                            {/if}
                        </div>
                    </li>
                {/if}
                {if !empty($PLUGIN_INDEX_BUTTONS)}
                    {foreach $PLUGIN_INDEX_BUTTONS as $button}<li>{$button}</li>
                    {/foreach}
                {/if}
                {if !empty($PLUGIN_INDEX_ACTIONS)}{$PLUGIN_INDEX_ACTIONS}{/if}
                {if ((!empty($CATEGORIES) && !isset($GDThumb)) || (!empty($THUMBNAILS) && !isset($GThumb) && !isset($GDThumb))) && ($theme_config->category_wells == 'never' || ($theme_config->category_wells == 'mobile_only' && functions::get_device() == 'desktop'))}
                    <li id="btn-grid"
                        class="nav-item{if isset($smarty.cookies.view) and $smarty.cookies.view != 'list'} active{/if}">
                        <a class="nav-link" href="javascript:;" title="{'Grid view'|translate}">
                            <i class="fas fa-th fa-fw"></i><span class="d-lg-none ms-2">{'Grid view'|translate}</span>
                        </a>
                    </li>
                    <li id="btn-list"
                        class="nav-item{if !empty($smarty.cookies.view) && $smarty.cookies.view == 'list'} active{/if}">
                        <a class="nav-link" href="javascript:;" title="{'List view'|translate}">
                            <i class="fas fa-th-list fa-fw"></i><span class="d-lg-none ms-2">{'List view'|translate}</span>
                        </a>
                    </li>
                {/if}
            </ul>
        </div>
    </div>
</nav>

{include file='infos_errors.tpl'}

{if !empty($SEARCH_ID)}
    <div class="container{if $theme_config->fluid_width}-fluid{/if}">
        {include file='themes/default/template/inc/search_filters.inc.tpl'}
    </div>
{/if}

<div class="container{if $theme_config->fluid_width}-fluid{/if}">
    {if !empty($PLUGIN_INDEX_CONTENT_BEGIN)}{$PLUGIN_INDEX_CONTENT_BEGIN}{/if}

    {if isset($chronology_views)}
        <div id="calendar-select" class="btn-group">
            <button id="calendar-view" type="button" class="btn btn-primary btn-raised dropdown-toggle"
                data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                {foreach $chronology_views as $view}
                    {if $view.SELECTED}{$view.CONTENT}
                    {/if}
                {/foreach}
            </button>
            <div class="dropdown-menu" aria-labelledby="calendar-view">
                {foreach $chronology_views as $view}
                    <a class="dropdown-item {if $view.SELECTED} active{/if}" href="{$view.VALUE}">{$view.CONTENT}</a>
                {/foreach}
            </div>
        </div>
    {/if}

    {if isset($FILE_CHRONOLOGY_VIEW)}
        {include file=$FILE_CHRONOLOGY_VIEW}
    {/if}

    {if isset($SEARCH_IN_SET_BUTTON) and $SEARCH_IN_SET_BUTTON}
        <div class="mcs-side-results search-in-set-button ">
            <div>
                <p><a href="{$SEARCH_IN_SET_URL}" class="">
                        <i class="fas fa-share-square"></i>
                        {'Search in this set'|translate}</a></p>
            </div>
        </div>
    {/if}

    {if !empty($CONTENT_DESCRIPTION)}
        <div id="content-description" class="py-3{if $theme_config->thumbnail_cat_desc == 'simple'} text-center{/if}">
            {if $theme_config->thumbnail_cat_desc == 'simple'}
                <h5>{$CONTENT_DESCRIPTION}</h5>
            {else}
                {$CONTENT_DESCRIPTION}
            {/if}
        </div>
    {/if}
    <div id="content"
        class="{if !empty($smarty.cookies.view) && $smarty.cookies.view == 'list'}content-list{else}content-grid{/if}{if empty($CONTENT_DESCRIPTION)} pt-3{/if}">
        {if !empty($CONTENT)}
            <!-- Start of content -->
            {$CONTENT}
            <!-- End of content -->
        {/if}

        {if !empty($CATEGORIES)}
            <!-- Start of categories -->
            {$CATEGORIES}
            {footer_script}<script>
                document.addEventListener('DOMContentLoaded', function() {
                    var elements = document.querySelectorAll('#content .col-outer .card-body');
                    elements.forEach(function(el) {
                        var hasTitle = el.querySelector(':scope > .card-title');
                        if (hasTitle) {
                            equalHeights();
                            return;
                        }
                    });
                });
            </script>{/footer_script}
            <!-- End of categories -->
        {/if}

        {if !empty($category_search_results)}
            <div class="container{if $theme_config->fluid_width}-fluid{/if}">
                <h3 class="category_search_results">{'Album results for'|translate}
                    <em><strong>{$QUERY_SEARCH}</strong></em>
                </h3>
                <p>
                    <em><strong>
                            {foreach from=$category_search_results item=res name=res_loop}
                                {if !$smarty.foreach.res_loop.first} &mdash; {/if}
                                {$res}
                            {/foreach}
                        </strong></em>
                </p>
            </div>
        {/if}

        {if !empty($tag_search_results)}
            <div class="container{if $theme_config->fluid_width}-fluid{/if}">
                <h3 class="tag_search_results">{'Tag results for'|translate} <em><strong>{$QUERY_SEARCH}</strong></em></h3>
                <p>
                    <em><strong>
                            {foreach from=$tag_search_results item=tag name=res_loop}
                                {if !$smarty.foreach.res_loop.first} &mdash; {/if}
                                <a href="{$tag.URL}">{$tag.name}</a>
                            {/foreach}
                        </strong></em>
                </p>
            </div>
        {/if}

        {if !empty($THUMBNAILS)}
            <!-- Start of thumbnails -->
            <div id="thumbnails" class="row">{$THUMBNAILS}</div>
            {footer_script}<script>
                document.addEventListener('DOMContentLoaded', function() {
                    var images = document.querySelectorAll('#content img');
                    images.forEach(function(img) {
                        img.addEventListener('load', function() {
                            equalHeights();
                        });
                    });
                });
            </script>{/footer_script}
            {footer_script}<script>
                {if !isset($loaded_plugins['piwigo-videojs']) && (isset($GThumb) || isset($GDThumb))}
                    function addVideoIndicator() {
                        var images = document.querySelectorAll('img.thumbnail[src*="pwg_representative"]');
                        images.forEach(function(img) {
                            var li = img.closest('li');
                            if (li) {
                                li.insertAdjacentHTML('beforeend', '<i class="fas fa-file-video fa-2x video-indicator" aria-hidden="true" style="position: absolute; top: 10px; left: 10px; z-index: 100; color: #fff;"></i>');
                            }
                        });
                    }
                    document.addEventListener('DOMContentLoaded', function() {
                        addVideoIndicator();
                    });
                    document.addEventListener('ajaxComplete', function() {
                        addVideoIndicator();
                    });
                {else}
                    var cardThumbnails = document.querySelectorAll('.card-thumbnail');
                    cardThumbnails.forEach(function(card) {
                        var img = card.querySelector('img[src*="pwg_representative"]');
                        if (img) {
                            var div = img.closest('div');
                            if (div) {
                                div.insertAdjacentHTML('beforeend', '<i class="fas fa-file-video fa-2x video-indicator" aria-hidden="true" style="position: absolute; top: 10px; left: 10px; z-index: 100; color: #fff;"></i>');
                            }
                        }
                    });
                {/if}
            </script>{/footer_script}
            <!-- End of thumbnails -->
        {else if !empty($SEARCH_ID)}
            <div class="mcs-no-result">
                <div class="text">
                    <span class="top">{'No results are available.'|translate}</span>
                    <span class="bot">{'You can try to edit your filters and perform a new search.'|translate}</span>
                </div>
            </div>
        {/if}
    </div>
</div>
{if !empty($cats_navbar) || !empty($thumb_navbar)}
    <div class="container{if $theme_config->fluid_width}-fluid{/if}">
        {if !empty($cats_navbar)}
            {include file='navigation_bar.tpl' fragment="content"|get_extent:'navbar' navbar=$cats_navbar}
        {/if}
        {if !empty($thumb_navbar) && !isset($loaded_plugins['rv_tscroller'])}
            {include file='navigation_bar.tpl' fragment="content"|get_extent:'navbar' navbar=$thumb_navbar}
        {/if}
    </div>
{/if}

<div class="container{if $theme_config->fluid_width}-fluid{/if}">
    {if !empty($PLUGIN_INDEX_CONTENT_END)}{$PLUGIN_INDEX_CONTENT_END}{/if}
</div>

{if !empty($PLUGIN_INDEX_CONTENT_AFTER)}{$PLUGIN_INDEX_CONTENT_AFTER}{/if}
<!-- End of index.tpl -->