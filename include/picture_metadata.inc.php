<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang, $url_self, $picture, $related_categories, $comment_action;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * This file is included by the picture page to manage picture metadata
 *
 */


include_once(PHPWG_ROOT_PATH.'/include/functions_metadata.inc.php');
if ((\Piwigo\Config\Config::showExif()) and (function_exists('exif_read_data'))) {
    $exif_mapping = [];
    foreach (\Piwigo\Config\Config::showExifFields() as $field) {
        $exif_mapping[$field] = $field;
    }

    $exif = get_exif_data($picture['current']['src_image']->get_path(), $exif_mapping);

    if (count($exif) > 0) {
        $tpl_meta = [
            'TITLE' => l10n('EXIF Metadata'),
            'lines' => [],
          ];

        foreach (\Piwigo\Config\Config::showExifFields() as $field) {
            if (!str_contains((string) $field, ';')) {
                // template cannot deal with an array as value, we skip it
                if (isset($exif[$field]) and !is_array($exif[$field])) {
                    $key = $field;
                    if (isset($lang['exif_field_'.$field])) {
                        $key = $lang['exif_field_'.$field];
                    }
                    $tpl_meta['lines'][$key] = $exif[$field];
                }
            } else {
                $tokens = explode(';', (string) $field);
                // template cannot deal with an array as value, we skip it
                if (isset($exif[$field]) and !is_array($exif[$field])) {
                    $key = $tokens[1];
                    if (isset($lang['exif_field_'.$key])) {
                        $key = $lang['exif_field_'.$key];
                    }
                    $tpl_meta['lines'][$key] = $exif[$field];
                }
            }
        }
        $template->append('metadata', $tpl_meta);
    }
}

if (\Piwigo\Config\Config::showIptc()) {
    $iptc = get_iptc_data($picture['current']['src_image']->get_path(), \Piwigo\Config\Config::showIptcMapping(), ', ');

    if (count($iptc) > 0) {
        $tpl_meta = [
            'TITLE' => l10n('IPTC Metadata'),
            'lines' => [],
          ];

        foreach ($iptc as $field => $value) {
            $key = $field;
            if (isset($lang[$field])) {
                $key = $lang[$field];
            }
            $tpl_meta['lines'][$key] = $value;
        }
        $template->append('metadata', $tpl_meta);
    }
}
