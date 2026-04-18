<nav
    class="navbar navbar-contextual navbar-expand-lg {$theme_config->navbar_contextual_style} {$theme_config->navbar_contextual_bg} sticky-top mb-2">
    <div class="container{if $theme_config->fluid_width}-fluid{/if}">
        <div class="navbar-brand">
            <div class="nav-breadcrumb d-inline-flex">{$SECTION_TITLE}<span
                    class="nav-breadcrumb-item active">{$current.TITLE}</span></div>
        </div>
        <button type="button" class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#secondary-navbar"
            aria-controls="secondary-navbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="fas fa-bars"></span>
        </button>
        <div class="navbar-collapse collapse justify-content-end" id="secondary-navbar">
            <ul class="navbar-nav">
                {if isset($current.unique_derivatives) && count($current.unique_derivatives)>1}
                    {footer_script}<script>
                        function changeImgSrc(url, typeSave, typeMap) {
                            var theImg = document.getElementById("theMainImage");
                            if (theImg) {
                                theImg.removeAttribute("width");
                                theImg.removeAttribute("height");
                                var parts = url.split('/');
                                var encodedParts = parts.map(function(part, index) {
                                    if (index === 0) {
                                        return part;
                                    }
                                    if (index === 1 && part.startsWith("i.php?")) {
                                        var rest = parts.slice(1).join('/');
                                        var restEncoded = encodeURIComponent(rest);
                                        return "i.php?" + restEncoded;
                                    } else {
                                        return encodeURIComponent(part);
                                    }
                                })
                                var encodedUrl = encodedParts.join('/');
                                theImg.src = encodedUrl;
                                theImg.useMap = "#map" + typeMap;
                            }
                            var activeItems = document.querySelectorAll('.derivative-li');
                            activeItems.forEach(function(item) {
                                item.classList.remove('active');
                            });
                            var targetItem = document.getElementById('derivative' + typeMap);
                            if (targetItem) {
                                targetItem.classList.add('active');
                            }
                            document.cookie = 'picture_deriv='+typeSave+';path={$COOKIE_PATH}';
                        }
                    </script>{/footer_script}
                    <li class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown"
                            title="{'Photo sizes'|translate}">
                            <i class="far fa-image fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'Photo sizes'|translate}</span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end" role="menu">
                            {foreach $current.unique_derivatives as $derivative_type => $derivative}
                                <a id="derivative{$derivative->get_type()}"
                                    class="dropdown-item derivative-li{if $derivative->get_type() == $current.selected_derivative->get_type()} active{/if}"
                                    href="javascript:changeImgSrc('{$derivative->get_url()|escape:javascript}','{$derivative_type}','{$derivative->get_type()}')"
                                    rel="nofollow">
                                    {$derivative->get_type()|translate}<span class="derivativeSizeDetails">
                                        ({$derivative->get_size_hr()})</span>
                                </a>
                            {/foreach}
                            {if isset($U_ORIGINAL)}
                                <a class="dropdown-item"
                                    href="javascript:phpWGOpenWindow('{$U_ORIGINAL}','xxx','scrollbars=yes,toolbar=no,status=no,resizable=yes')"
                                    rel="nofollow">{'Original'|translate}</a>
                            {/if}
                        </div>
                    </li>
                {/if}
                {if isset($U_SLIDESHOW_START)}
                    <li class="nav-item">
                        <a class="nav-link" href="{$U_SLIDESHOW_START}"
                            title="{'slideshow'|translate}" id="startSlideshow" rel="nofollow">
                            <i class="fas fa-play fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2 text-capitalize">{'slideshow'|translate}</span>
                        </a>
                    </li>
                {/if}
                {*{if isset($U_METADATA)}
                <li class="nav-item">
                    <a class="nav-link" href="{$U_METADATA}" title="{'Show file metadata'|translate}" rel="nofollow">
                        <i class="fas fa-camera-retro fa-fw" aria-hidden="true"></i><span class="d-lg-none ms-2">{'Show file metadata'|translate}</span>
                    </a>
                </li>
{/if}*}
                {if isset($current.U_DOWNLOAD)}
                    {if empty($current.formats)}
                        <li class="nav-item">
                            <a id="downloadSwitchLink" class="nav-link" href="{$current.U_DOWNLOAD}"
                                title="{'Download this file'|translate}" rel="nofollow">
                                <i class="fas fa-download fa-fw" aria-hidden="true"></i><span
                                    class="d-lg-none ms-2">{'Download this file'|translate}</span>
                            </a>
                        {else}
                        <li class="nav-item dropdown">
                            <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true"
                                title="{'Download this file'|translate}">
                                <i class="fas fa-download fa-fw" aria-hidden="true"></i><span
                                    class="d-lg-none ms-2">{'Download this file'|translate}</span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" role="menu">
                                {foreach $current.formats as $format}
                                    <li class="dropdown-item"><a href="{$format.download_url}" rel="nofollow">{$format.label}<span
                                                class="downloadformatDetails"> ({$format.filesize})</span></a></li>
                                {/foreach}
                            </ul>
                        {/if} {* has formats *}
                    </li>
                {/if}
                {if isset($favorite)}
                    <li class="nav-item">
                        <a class="nav-link" href="{$favorite.U_FAVORITE}"
                            title="{if $favorite.IS_FAVORITE}{'delete this photo from your favorites'|translate}{else}{'add this photo to your favorites'|translate}{/if}"
                            rel="nofollow">
                            <i class="fa{if !$favorite.IS_FAVORITE}r{else}s{/if} fa-heart fa-fw"></i><span
                                class="d-lg-none ms-2">{if $favorite.IS_FAVORITE}{'delete this photo from your favorites'|translate}{else}{'add this photo to your favorites'|translate}{/if}</span>
                        </a>
                    </li>
                {/if}
                {if isset($U_SET_AS_REPRESENTATIVE)}
                    <li class="nav-item">
                        <a class="nav-link" id="cmdSetRepresentative" href="{$U_SET_AS_REPRESENTATIVE}"
                            title="{'set as album representative'|translate}" rel="nofollow">
                            <i class="fas fa-link fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'set as album representative'|translate}</span>
                        </a>
                    </li>
                {/if}
                {if isset($U_PHOTO_ADMIN)}
                    <li class="nav-item">
                        <a class="nav-link" id="cmdEditPhoto" href="{$U_PHOTO_ADMIN}"
                            title="{'Modify information'|translate}" rel="nofollow">
                            <i class="fas fa-pencil-alt fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'Modify information'|translate}</span>
                        </a>
                    </li>
                {/if}
                {if isset($U_CADDIE)}
                    {footer_script}<script>
                        function addToCadie(aElement, rootUrl, id) {
                            if (aElement.disabled) return;
                            aElement.disabled = true;
                            var y = new PwgWS(rootUrl);
                            y.callService("pwg.caddie.add", { image_id: id }, {
                                onFailure: function(num, text) {
                                    alert(num + " " + text);
                                    document.location = aElement.href;
                                },
                                onSuccess: function(result) { aElement.disabled = false; }
                            });
                        }
                    </script>{/footer_script}
                    <li class="nav-item">
                        <a class="nav-link" href="{$U_CADDIE}"
                            onclick="addToCadie(this, '{$ROOT_URL}', {$current.id}); return false;"
                            title="{'Add to caddie'|translate}" rel="nofollow">
                            <i class="fas fa-shopping-basket fa-fw" aria-hidden="true"></i><span
                                class="d-lg-none ms-2">{'Add to caddie'|translate}</span>
                        </a>
                    </li>
                {/if}
                {if isset($PLUGIN_PICTURE_BUTTONS)}
                    {foreach $PLUGIN_PICTURE_BUTTONS as $button}{$button}
                    {/foreach}
                {/if}
                {if isset($PLUGIN_PICTURE_ACTIONS)}{$PLUGIN_PICTURE_ACTIONS}{/if}
            </ul>
        </div>
    </div>
</nav>