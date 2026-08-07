<?php

declare(strict_types=1);

$themeconf = [
    'name' => 'standard_pages',
    'parent' => 'default',
    'load_parent_css' => false,
    'img_dir' => 'themes/standard_pages/images',
];

// send stantard pages conf options to tpl.
// $theme_template_vars is set by Template::load_themeconf(), which assigns
// it to the calling theme's Template instance after this include.
// Singleton/service-locator elimination campaign, Phase 12 sub-phase
// 12F-12: this file previously called the now-deleted
// CurrentConfig::current() shim -- \Piwigo\Template\Template::
// currentConfig() replaces it (a real, ordinary static method call,
// unlike $this->currentConfig: this file genuinely IS `include`d from
// inside the Template instance's own load_themeconf() method, but
// PHPStan analyses every file independently and can't trace that
// inherited scope, so a `$this` property read here would report as
// undefined even though it works at runtime).
$theme_template_vars = [
    'STD_PGS_SELECTED_SKIN' => \Piwigo\Template\Template::currentConfig()->standardPagesSelectedSkin(),
    'STD_PGS_SELECTED_LOGO' => \Piwigo\Template\Template::currentConfig()->standardPagesSelectedLogo(),
    // Former `$page['gallery_title'] ?? CurrentConfig::galleryTitle()` --
    // nothing writes $page['gallery_title'] anywhere anymore (confirmed
    // via a repo-wide grep), so the fallback always won in practice
    // already.
    'GALLERY_TITLE' => \Piwigo\Template\Template::currentConfig()->galleryTitle(),
];
