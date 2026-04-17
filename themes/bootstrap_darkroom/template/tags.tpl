<nav
    class="navbar navbar-contextual navbar-expand-lg {$theme_config->navbar_contextual_style} {$theme_config->navbar_contextual_bg} sticky-top mb-5">
    <div class="container{if $theme_config->fluid_width}-fluid{/if}">
        <div class="navbar-brand me-auto"><a href="{$U_HOME}">{'Home'|translate}</a>{$LEVEL_SEPARATOR}<a
                href>{'Tags'|translate}</a></div>
        <ul class="navbar-nav justify-content-end">
            {if $display_mode != 'cloud'}
                <li class="nav-item">
                    <a class="nav-link" href="{$U_CLOUD}" title="{'show tag cloud'|translate}">
                        <i class="fas fa-cloud fa-fw" aria-hidden="true"></i><span class="d-lg-none">
                            {'show tag cloud'|translate}</span>
                    </a>
                </li>
            {/if}
            {if $display_mode != 'letters'}
                <li class="nav-item">
                    <a class="nav-link" href="{$U_LETTERS}" title="{'group by letters'|translate}" rel="nofollow">
                        <i class="fas fa-sort-alpha-down fa-fw" aria-hidden="true"></i><span class="d-lg-none">
                            {'group by letters'|translate}</span>
                    </a>
                </li>
            {/if}
            {if isset($loaded_plugins['tag_groups']) && $display_mode != 'groups'}
                <li class="nav-item">
                    <a class="nav-link" href="{$U_TAG_GROUPS}" title="{'show tag groups'|translate}" rel="nofollow">
                        <i class="fas fa-tags fa-fw" aria-hidden="true"></i><span class="d-lg-none">
                            {'show tag groups'|translate}</span>
                    </a>
                </li>
            {/if}
            {if !empty($PLUGIN_INDEX_ACTIONS)}{$PLUGIN_INDEX_ACTIONS}{/if}
        </ul>
    </div>
</nav>

{include file='infos_errors.tpl'}

<div class="container{if $theme_config->fluid_width}-fluid{/if}">

    {if $display_mode == 'cloud' and isset($tags)}
        {if $theme_config->tag_cloud_type == 'basic'}
            <div id="tagCloud">
                {foreach $tags as $tag}
                    <span><a href="{$tag.URL}" class="tagLevel{$tag.level}"
                            title="{$tag.counter|translate_dec:'%d photo':'%d photos'}">{$tag.name}</a></span>
                {/foreach}
            </div>
        {else}
            {combine_script id='wordcloud2' load='footer' path="themes/bootstrap_darkroom/node_modules/wordcloud/src/wordcloud2.js"}
            {footer_script require='wordcloud2'}<script>
    document.addEventListener('DOMContentLoaded', function() {
        var canvas = document.getElementById('tagCloudCanvas');
        if (!canvas || typeof WordCloud === 'undefined') return;
        var startGradient = document.getElementById('tagCloudGradientStart');
        var endGradient = document.getElementById('tagCloudGradientEnd');
        var startColor = startGradient ? window.getComputedStyle(startGradient).color : '#333';
        var endColor = endGradient ? window.getComputedStyle(endGradient).color : '#aaa';
        var list = Array.from(document.querySelectorAll('#tagCloudData span[data-weight]')).map(function(span) {
            return [span.textContent, parseFloat(span.dataset.weight) || 1];
        });
        WordCloud(canvas, {
            list: list,
            color: function(word, weight, fontSize) {
                return startColor;
            },
            rotateRatio: 0.2,
            fontFamily: "'Helvetica Neue',Helvetica,Arial,sans-serif",
            shape: 'circle',
            backgroundColor: 'transparent',
            click: function(item) {
                var span = Array.from(document.querySelectorAll('#tagCloudData span[data-weight]'))
                    .find(function(s) { return s.textContent === item[0]; });
                if (span && span.dataset.href) window.location.href = span.dataset.href;
            }
        });
        canvas.style.cursor = 'pointer';
    });
</script>{/footer_script}
            <canvas id="tagCloudCanvas" width="600" height="400" style="width:100%;max-width:600px"></canvas>
            <div id="tagCloudData" style="display:none">
                {foreach $tags as $tag}
                    <span data-weight="{$tag.counter}" data-href="{$tag.URL}">{$tag.name}</span>
                {/foreach}
            </div>
            <div id="tagCloudGradientStart"></div>
            <div id="tagCloudGradientEnd"></div>
        {/if}
    {/if}

    {if $display_mode == 'letters' and isset($letters)}
        <div id="tagLetters">
            {foreach $letters as $letter}
                <div class="card w-100 mb-3">
                    <div class="card-header">{$letter.TITLE}</div>
                    <div class="list-group list-group-flush">
                        {foreach $letter.tags as $tag}
                            <a href="{$tag.URL}" class="list-group-item list-group-item-action" title="{$tag.name}">{$tag.name}<span
                                    class="badge bg-secondary ms-2">{$tag.counter|translate_dec:'%d photo':'%d photos'}</span></a>
                        {/foreach}
                    </div>
                </div>
            {/foreach}
        </div>
    {/if}

</div> <!-- content -->