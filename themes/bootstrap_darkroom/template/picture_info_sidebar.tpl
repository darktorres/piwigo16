{footer_script}<script>
    document.addEventListener('DOMContentLoaded', function() {
        var wrapper = document.getElementById("wrapper");
        if (wrapper) {
            wrapper.style.position = "relative";
            wrapper.style.overflowX = "hidden";
        }
    });
</script>{/footer_script}
<div id="sidebar">
    <div id="info-content" class="info">
        <dl id="standard" class="imageInfoTable">
            <h4>{'Information'|translate}</h4>
            {if $display_info.author and isset($INFO_AUTHOR)}
                <div id="Author" class="imageInfo">
                    <dt>{'Author'|translate}</dt>
                    <dd>{$INFO_AUTHOR}</dd>
                </div>
            {/if}
            {if isset($CR_INFO_NAME) && !empty($CR_INFO_NAME)}
                <div id="Copyright" class="imageInfo">
                    <dt>{'Copyright'|translate}</dt>
                    <dd>{if isset($CR_INFO_URL)}<a href="{$CR_INFO_URL}">{$CR_INFO_NAME}</a>{else}{$CR_INFO_NAME}{/if}</dd>
                </div>
            {/if}
            {if $display_info.created_on and isset($INFO_CREATION_DATE)}
                <div id="datecreate" class="imageInfo">
                    <dt>{'Created on'|translate}</dt>
                    <dd>{$INFO_CREATION_DATE}</dd>
                </div>
            {/if}
            {if $display_info.posted_on}
                <div id="datepost" class="imageInfo">
                    <dt>{'Posted on'|translate}</dt>
                    <dd>{$INFO_POSTED_DATE}</dd>
                </div>
            {/if}
            {if $display_info.visits}
                <div id="visits" class="imageInfo">
                    <dt>{'Visits'|translate}</dt>
                    <dd>{$INFO_VISITS}</dd>
                </div>
            {/if}
            {if $display_info.dimensions and isset($INFO_DIMENSIONS)}
                <div id="Dimensions" class="imageInfo">
                    <dt>{'Dimensions'|translate}</dt>
                    <dd>{$INFO_DIMENSIONS}</dd>
                </div>
            {/if}
            {if $display_info.file}
                <div id="File" class="imageInfo">
                    <dt>{'File'|translate}</dt>
                    <dd>{$INFO_FILE}</dd>
                </div>
            {/if}
            {if $display_info.filesize and isset($INFO_FILESIZE)}
                <div id="Filesize" class="imageInfo">
                    <dt>{'Filesize'|translate}</dt>
                    <dd>{$INFO_FILESIZE}</dd>
                </div>
            {/if}
            {if $display_info.tags and isset($related_tags)}
                <div id="Tags" class="imageInfo">
                    <dt>{'Tags'|translate}</dt>
                    <dd>
                        {foreach from=$related_tags item=tag name=tag_loop}
                            {if !$smarty.foreach.tag_loop.first},
                            {/if}<a href="{$tag.URL}">{$tag.name}</a>
                        {/foreach}
                    </dd>
                </div>
            {/if}
            {if $display_info.categories and isset($related_categories)}
                <div id="Categories" class="imageInfo">
                    <dt>{'Albums'|translate}</dt>
                    <dd>
                        {foreach from=$related_categories item=cat name=cat_loop}
                            {if !$smarty.foreach.cat_loop.first}<br />{/if}{$cat}
                        {/foreach}
                    </dd>
                </div>
            {/if}
            {if $display_info.rating_score and isset($rate_summary)}
                <div id="Average" class="imageInfo">
                    <dt>{'Rating score'|translate}</dt>
                    <dd>
                        {if $rate_summary.count}
                            <span id="ratingScore">{$rate_summary.score}</span> <span
                                id="ratingCount">({$rate_summary.count|translate_dec:'%d rate':'%d rates'})</span>
                        {else}
                            <span id="ratingScore">{'no rate'|translate}</span> <span id="ratingCount"></span>
                        {/if}
                    </dd>
                </div>
            {/if}

            {if isset($rating)}
                <div id="rating" class="imageInfo">
                    <dt id="updateRate">
                        {if isset($rating.USER_RATE)}{'Update your rating'|translate}{else}{'Rate this photo'|translate}{/if}
                    </dt>
                    <dd>
                        <form action="{$rating.F_ACTION}" method="post" id="rateForm" style="margin:0;">
                            <div>
                                {foreach $rating.marks as $mark}
                                    {if isset($rating.USER_RATE) && $mark==$rating.USER_RATE}
                                        <span class="rateButtonStarFull" data-value="{$mark}"></span>
                                    {else}
                                        <span class="rateButtonStarEmpty" data-value="{$mark}"></span>
                                    {/if}
                                {/foreach}
                                {footer_script}<script type="module">
                                    import { initRating, makeNiceRatingForm } from './themes/bootstrap_darkroom/js/rating.js';
                                    document._pwgRatingQueue = document._pwgRatingQueue || [];
                                    document._pwgRatingQueue.push( { rootUrl: '{$ROOT_URL}', image_id: {$current.id},
                                    onSuccess: function(rating) {
                                    var e = document.getElementById("updateRate");
                                    if (e) e.innerHTML = "{'Update your rating'|translate|escape:'javascript'}";
                                    e = document.getElementById("ratingScore");
                                    if (e) e.innerHTML = rating.score;
                                    e = document.getElementById("ratingCount");
                                    if (e) {
                                        if (rating.count == 1) {
                                            e.innerHTML = "({'%d rate'|translate|escape:'javascript'})".replace( "%d", rating.count);
                                        } else {
                                            e.innerHTML = "({'%d rates'|translate|escape:'javascript'})".replace( "%d", rating.count);
                                        }
                                    }
                                    var averageRateEl = document.getElementById('averageRate');
                                    if (averageRateEl) {
                                        var spans = averageRateEl.querySelectorAll('span');
                                        spans.forEach(function(span) {
                                            var value = parseFloat(span.dataset.value);
                                            if (rating.average > value - 0.5) {
                                                span.classList.add('rateButtonStarFull');
                                                span.classList.remove('rateButtonStarEmpty');
                                            } else {
                                                span.classList.add('rateButtonStarEmpty');
                                                span.classList.remove('rateButtonStarFull');
                                            }
                                        });
                                    }
                                    }
                                    });
                                </script>{/footer_script}
                            </div>
                        </form>
                    </dd>
                </div>
            {/if}
            {if $display_info.privacy_level and isset($available_permission_levels)}
                {footer_script}<script>
                    function setPrivacyLevel(id, level, label) {
                        (new PwgWS('{$ROOT_URL}')).callService(
                        "pwg.images.setPrivacyLevel",
                        { image_id: id, level: level },
                        {
                            method: "POST",
                            onFailure: function(num, text) { alert(num + " " + text); },
                            onSuccess: function(result) {
                                var dropdown = document.getElementById('dropdownPermissions');
                                if (dropdown) dropdown.innerHTML = label;
                                var permLis = document.querySelectorAll('.permission-li');
                                permLis.forEach(function(li) {
                                    li.classList.remove('active');
                                });
                                var permElement = document.getElementById('permission-' + level);
                                if (permElement) permElement.classList.add('active');
                            }
                        }
                    );
                    }
                    (document._switchBoxQueue = document._switchBoxQueue || []).push("#privacyLevelLink", "#privacyLevelBox");
                </script>{/footer_script}
                <div id="Privacy" class="imageInfo">
                    <dt>{'Who can see this photo?'|translate}</dt>
                    <dd>
                        <div class="dropdown">
                            <button class="btn btn-primary dropdown-toggle ellipsis" type="button" id="dropdownPermissions"
                                data-bs-toggle="dropdown" aria-expanded="true">
                                {$available_permission_levels[$current.level]}
                            </button>
                            <div class="dropdown-menu dropdown-menu-end" role="menu"
                                aria-labelledby="dropdownPermissions">
                                {foreach $available_permission_levels as $level => $label}
                                    <a id="permission-{$level}"
                                        class="dropdown-item permission-li {if $current.level == $level} active{/if}"
                                        href="javascript:setPrivacyLevel({$current.id},{$level},'{$label}')">{$label}</a>
                                {/foreach}
                            </div>
                        </div>
                    </dd>
                </div>
            {/if}
            {if isset($metadata)}
                <div id="metadata" class="imageInfo">
                    {foreach $metadata as $meta}
                        <br />
                        <h4>{$meta.TITLE}</h4>
                        {foreach $meta.lines as $label => $value}
                            <dt>{$label}</dt>
                            <dd>{$value}</dd>
                        {/foreach}
                    {/foreach}
                </div>
            {/if}
        </dl>
    </div>
    <div class="handle">
        <a id="info-link" href="#">
            <span class="fas fa-info" aria-hidden="true"></span>
        </a>
    </div>
</div>