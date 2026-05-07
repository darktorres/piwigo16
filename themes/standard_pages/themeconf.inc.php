<?php

$themeconf = array(
  'name' => 'standard_pages',
  'parent' => '_base',
  'load_parent_css' => false,
  'img_dir' => 'themes/standard_pages/images',
);

//send stantard pages conf options to tpl
$this->assign(
    array(
    'STD_PGS_SELECTED_SKIN' => ServiceLocator::get(ConfigService::class)->confGetParam('standard_pages_selected_skin', 'default'),
    'STD_PGS_SELECTED_LOGO' => ServiceLocator::get(ConfigService::class)->confGetParam('standard_pages_selected_logo', 'piwigo_logo'),
    'GALLERY_TITLE' => isset($page['gallery_title']) ? $page['gallery_title'] : \Piwigo\Config\Config::galleryTitle(),
  )
);

//Send custom logo path if custom_logo is the selected option
if ('custom_logo' == ServiceLocator::get(ConfigService::class)->confGetParam('standard_pages_selected_logo', 'piwigo_logo')) {
    $this->assign(
        array(
        'STD_PGS_SELECTED_LOGO_PATH' => ServiceLocator::get(ConfigService::class)->confGetParam('standard_pages_selected_logo_path', ''),
    )
    );
}
