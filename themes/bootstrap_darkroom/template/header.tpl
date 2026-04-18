<!DOCTYPE html>
<html lang="{$lang_info.code}" dir="{$lang_info.direction}">

<head>
  <meta http-equiv="Content-Type" content="text/html; charset={$CONTENT_ENCODING}">
  <meta name="generator" content="Piwigo (aka PWG), see piwigo.org">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  {if $meta_ref_enabled}
    {if isset($INFO_AUTHOR)}
      <meta name="author" content="{$INFO_AUTHOR|strip_tags:false|replace:'"':' '}">
  {/if}
  {if isset($related_tags)}
  <meta name="keywords"
    content="{foreach from=$related_tags item=tag name=tag_loop}{if !$smarty.foreach.tag_loop.first}, {/if}{$tag.name}{/foreach}">
  {/if}
  {if isset($COMMENT_IMG)}
  <meta name="description"
    content="{$COMMENT_IMG|strip_tags:false|replace:'"':' '}{if isset($INFO_FILE)} - {$INFO_FILE}{/if}">
    {else}
      <meta name="description" content="{$PAGE_TITLE}{if isset($INFO_FILE)} - {$INFO_FILE}{/if}">
    {/if}
  {/if}

  <title>{if $PAGE_TITLE!=functions::l10n('Home') && $PAGE_TITLE!=$GALLERY_TITLE}{$PAGE_TITLE} | {/if}{$GALLERY_TITLE}</title>
  <link rel="shortcut icon" type="image/x-icon" href="{$ROOT_URL}{$themeconf.icon_dir}/favicon.ico">
  <link rel="icon" sizes="192x192" href="{$ROOT_URL}themes/bootstrap_darkroom/img/logo.png">
  <link rel="apple-touch-icon" sizes="192x192" href="{$ROOT_URL}themes/bootstrap_darkroom/img/logo.png">
  <link rel="manifest" href="{$ROOT_URL}manifest.json">
  <link rel="start" title="{'Home'|translate}" href="{$U_HOME}">
  <link rel="search" title="{'Search'|translate}" href="{$ROOT_URL}search.php">
  {if isset($first.U_IMG)}
    <link rel="first" title="{'First'|translate}" href="{$first.U_IMG}">
  {/if}
  {if isset($previous.U_IMG)}
    <link rel="prev" title="{'Previous'|translate}" href="{$previous.U_IMG}">
  {/if}
  {if isset($next.U_IMG)}
    <link rel="next" title="{'Next'|translate}" href="{$next.U_IMG}">
  {/if}
  {if isset($last.U_IMG)}
    <link rel="last" title="{'Last'|translate}" href="{$last.U_IMG}">
  {/if}
  {if isset($U_UP)}
    <link rel="up" title="{'Thumbnails'|translate}" href="{$U_UP}">
  {/if}
  {if isset($U_PREFETCH)}
    <link rel="prefetch" href="{$U_PREFETCH}">
  {/if}
  {if isset($U_CANONICAL)}
    <link rel="canonical" href="{$U_CANONICAL}">
  {/if}
  {if not empty($page_refresh)}
    <meta http-equiv="refresh" content="{$page_refresh.TIME};url={$page_refresh.U_REFRESH}">
  {/if}

  {if $theme_config->bootstrap_theme} {* see https://github.com/tkuther/piwigo-bootstrap-darkroom/issues/104 *}
    {combine_css path="themes/bootstrap_darkroom/css/{$theme_config->bootstrap_theme}/bootstrap.css" order=-20}
  {else}
    {combine_css path="themes/bootstrap_darkroom/css/bootstrap-default/bootstrap.css" order=-20}
  {/if}
  {if $theme_config->bootstrap_theme == 'bootstrap-darkroom' || $theme_config->bootstrap_theme == 'material-darkroom'}
    {combine_css path="themes/bootstrap_darkroom/node_modules/typeface-pt-sans/index.css" order=-19}
  {elseif (0 === strpos($theme_config->bootstrap_theme, 'material')) || $theme_config->bootstrap_theme == 'bootstrap-default'}
    {combine_css path="themes/bootstrap_darkroom/node_modules/typeface-roboto/index.css" order=-19}
  {/if}
  {combine_css path='themes/bootstrap_darkroom/node_modules/@fortawesome/fontawesome-free/css/all.css' order=-14}
  {combine_css path='themes/bootstrap_darkroom/assets/photography-icons/css/PhotographyIcons.css' order=-13}
  {combine_css path='themes/bootstrap_darkroom/node_modules/bootstrap-social/bootstrap-social.css' order=-12}
  {foreach $themes as $theme}
    {if $theme.load_css}
      {combine_css path="themes/`$theme.id`/theme.css" order=-10}
    {/if}
    {if !empty($theme.local_head)}{include file=$theme.local_head load_css=$theme.load_css}{/if}
  {/foreach}
  {if file_exists("local/bootstrap_darkroom/custom.css")}
    {combine_css path="local/bootstrap_darkroom/custom.css" order=10000}
  {/if}
  {get_combined_css}
  {if $BODY_ID == 'theAdditionalPage' || $BODY_ID == 'theHomePage' || $bootstrap_darkroom_core_js_in_header == true }
    {assign var=loc value="header"}
  {else}
    {assign var=loc value="footer"}
  {/if}
  {combine_script id='bootstrap' path='themes/bootstrap_darkroom/node_modules/bootstrap/dist/js/bootstrap.bundle.js' load=$loc}
  <script type="module">
    import '{$ROOT_URL}admin/themes/default/js/dist/{$vite_bd_theme}';
    import { initHeader } from '{$ROOT_URL}admin/themes/default/js/dist/{$vite_bd_header}';
    import { phpWGOpenWindow, PwgWS, setupPwgOpenWindow, setupPopuphelp } from '{$ROOT_URL}admin/themes/default/js/dist/{$vite_gallery_scripts}';
    document.addEventListener('DOMContentLoaded', initHeader);
    window.phpWGOpenWindow = phpWGOpenWindow;
    window.PwgWS = PwgWS;
    setupPwgOpenWindow();
    setupPopuphelp();
  </script>
  {get_combined_scripts load='header'}
  {if not empty($head_elements)}
    {foreach $head_elements as $elt}{$elt}
    {/foreach}
  {/if}
  <script>
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('{$ROOT_URL}sw.js');
    }
  </script>
</head>

<body id="{$BODY_ID}" class="{foreach $BODY_CLASSES as $class}{$class} {/foreach}" data-infos='{$BODY_DATA}'
  data-quicksearch-navbar="{if $theme_config->quicksearch_navbar}1{else}0{/if}"
  data-page-header="{$theme_config->page_header}"
  data-page-header-both-navs="{if $theme_config->page_header_both_navs}1{else}0{/if}"
  data-has-menubar="{if !empty($MENUBAR)}1{else}0{/if}">

  <div id="wrapper">
    {if isset($MENUBAR)}
      <nav
        class="navbar navbar-expand-lg navbar-main {$theme_config->navbar_main_bg} {if $theme_config->page_header == 'fancy'}navbar-dark navbar-transparent fixed-top{else}{$theme_config->navbar_main_style}{/if}">
        <div class="container{if $theme_config->fluid_width}-fluid{/if}">
          {if $theme_config->logo_image_enabled && $theme_config->logo_image_path !== ''}
            <a class="navbar-brand me-auto" href="{$U_HOME}"><img class="img-fluid"
                src="{$ROOT_URL}{$theme_config->logo_image_path}" alt="{$GALLERY_TITLE}" /></a>
          {else}
            <a class="navbar-brand me-auto" href="{$U_HOME}">{$GALLERY_TITLE}</a>
          {/if}
          <button type="button" class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#navbar-menubar"
            aria-controls="navbar-menubar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="fas fa-bars"></span>
          </button>
          <div class="collapse navbar-collapse" id="navbar-menubar">
            {if $theme_config->quicksearch_navbar}
              <form class="d-flex align-items-center navbar-form ms-auto" role="search" action="{$ROOT_URL}qsearch.php" method="get"
                id="quicksearch" onsubmit="return this.q.value!='' && this.q.value!=qsearch_prompt;">
                <i class="fas fa-search" title="{'Search'|translate}" aria-hidden="true"></i>
                <input type="text" name="q" id="qsearchInput" class="form-control" placeholder="{'Search'|translate}" />
              </form>
            {/if}
            {$MENUBAR}
          </div>
        </div>
      </nav>
    {/if}

    {if !isset($slideshow) && !empty($MENUBAR)}
      {if $theme_config->page_header == 'jumbotron'}
        <div class="jumbotron mb-0">
          <div class="container{if $theme_config->fluid_width}-fluid{/if}">
            <div id="theHeader">{$PAGE_BANNER}</div>
          </div>
        </div>
      {elseif $theme_config->page_header == 'fancy'}
        <div
          class="page-header{if !$theme_config->page_header_full || ($theme_config->page_header_full && !$is_homepage)} page-header-small{/if}">
          <div class="page-header-image"
            style="background-image: url({$theme_config->page_header_image}); transform: translate3d(0px, 0px, 0px);"></div>
          <div class="container">
            <div id="theHeader" class="content-center">
              {$PAGE_BANNER}
            </div>
          </div>
        </div>
      {/if}
    {/if}

    {if $theme_config->page_header == 'fancy' && $theme_config->page_header_both_navs && empty($MENUBAR)}
      {html_style}<style>
        .navbar-contextual {
          background-color: #000 !important;
          padding-top: 5px;
          padding-bottom: 5px;
        }
      </style>{/html_style}
    {/if}

    {if not empty($header_msgs)}
      {foreach $header_msgs as $msg}
      {/foreach}
    {/if}

    {if not empty($header_notes)}
      {foreach $header_notes as $note}
      {/foreach}
    {/if}
    <div id="bd_scroll_area" data-rvts-scroll>

<!-- End of header.tpl -->