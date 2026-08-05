<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;

$themeconf = [
    'name' => 'standard_pages',
    'parent' => 'default',
    'load_parent_css' => false,
    'img_dir' => 'themes/standard_pages/images',
];

// send stantard pages conf options to tpl.
// $theme_template_vars is set by Template::load_themeconf(), which assigns
// it to the calling theme's Template instance after this include.
$theme_template_vars = [
    'STD_PGS_SELECTED_SKIN' => CurrentConfig::current()->standardPagesSelectedSkin(),
    'STD_PGS_SELECTED_LOGO' => CurrentConfig::current()->standardPagesSelectedLogo(),
    // Former `$page['gallery_title'] ?? CurrentConfig::galleryTitle()` --
    // nothing writes $page['gallery_title'] anywhere anymore (confirmed
    // via a repo-wide grep), so the fallback always won in practice
    // already.
    'GALLERY_TITLE' => CurrentConfig::current()->galleryTitle(),
];
