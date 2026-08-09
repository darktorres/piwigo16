<?php

declare(strict_types=1);

use Piwigo\Template\Template;

$themeconf = [
    'name' => 'standard_pages',
    'parent' => 'default',
    'load_parent_css' => false,
    'img_dir' => 'themes/standard_pages/images',
];

// send stantard pages conf options to tpl.
// $theme_template_vars is set by Template::load_themeconf(), which assigns
// it to the calling theme's Template instance after this include.
// \Piwigo\Template\Template::currentConfig() (a real, ordinary static
// method call) is used here instead of $this->currentConfig: this file
// genuinely IS `include`d from inside the Template instance's own
// load_themeconf() method, but PHPStan analyses every file
// independently and can't trace that inherited scope, so a `$this`
// property read here would report as undefined even though it works at
// runtime.
$theme_template_vars = [
    'STD_PGS_SELECTED_SKIN' => Template::currentConfig()->standardPagesSelectedSkin,
    'STD_PGS_SELECTED_LOGO' => Template::currentConfig()->standardPagesSelectedLogo,
    'GALLERY_TITLE' => Template::currentConfig()->galleryTitle,
];
