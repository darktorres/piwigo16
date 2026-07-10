<?php

declare(strict_types=1);

// Bootstrap globals, set by include/common.inc.php.
/** @var array<string, mixed> $conf */
/** @var array<string, mixed> $page */
global $conf, $page;

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
    'STD_PGS_SELECTED_SKIN' => conf_get_param('standard_pages_selected_skin', 'default'),
    'STD_PGS_SELECTED_LOGO' => conf_get_param('standard_pages_selected_logo', 'piwigo_logo'),
    'GALLERY_TITLE' => $page['gallery_title'] ?? $conf['gallery_title'],
];

// Send custom logo path if custom_logo is the selected option
if (conf_get_param('standard_pages_selected_logo', 'piwigo_logo') == 'custom_logo') {
    $theme_template_vars['STD_PGS_SELECTED_LOGO_PATH'] = conf_get_param('standard_pages_selected_logo_path', '');
}
