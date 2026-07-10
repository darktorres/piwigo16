<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * This file is included by the picture page to manage picture metadata
 */

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $lang
 * @var \Template $template
 */
global $conf, $lang, $template;
// Set by picture.php, right before this include.
/**
 * trigger_change() (include/functions_plugins.inc.php) is only typed to
 * return mixed -- restate the shape plugins are expected to preserve, same
 * as picture.php's own @var immediately above its
 * `$picture = trigger_change(...)` call.
 *
 * @var array<string, array{
 *     id: string,
 *     file: string,
 *     path: string,
 *     comment: string|null,
 *     author: string|null,
 *     date_creation: string|null,
 *     date_available: string,
 *     width: string|null,
 *     height: string|null,
 *     hit: string,
 *     filesize: string|null,
 *     src_image: SrcImage,
 *     derivatives: array<string, DerivativeImage>,
 *     url: string,
 *     download_url?: string,
 *     TITLE: string,
 *     TITLE_ESC: string,
 *     ...
 * }> $picture
 */
global $picture;

include_once PHPWG_ROOT_PATH . '/include/functions_metadata.inc.php';
if (($conf['show_exif']) and (function_exists('exif_read_data'))) {
    // $conf's own values are only known as mixed -- narrow show_exif_fields
    // once here and reuse it for both foreach loops below (config_default.inc.php
    // documents it as a list of field names, some using the 'GROUP;FIELD' form).
    $show_exif_fields = $conf['show_exif_fields'] ?? null;
    if (! is_array($show_exif_fields)) {
        $show_exif_fields = [];
    }

    $exif_mapping = [];
    foreach ($show_exif_fields as $field) {
        if (! is_string($field)) {
            continue;
        }
        $exif_mapping[$field] = $field;
    }

    $exif = get_exif_data($picture['current']['src_image']->get_path(), $exif_mapping);

    if (count($exif) > 0) {
        $tpl_meta = [
            'TITLE' => l10n('EXIF Metadata'),
            'lines' => [],
        ];

        foreach ($show_exif_fields as $field) {
            if (! is_string($field)) {
                continue;
            }
            if (! str_contains($field, ';')) {
                // template cannot deal with an array as value, we skip it
                if (isset($exif[$field]) and ! is_array($exif[$field])) {
                    $key = $field;
                    if (isset($lang['exif_field_' . $field]) and is_string($lang['exif_field_' . $field])) {
                        $key = $lang['exif_field_' . $field];
                    }
                    $tpl_meta['lines'][$key] = $exif[$field];
                }
            } else {
                $tokens = explode(';', $field);
                // template cannot deal with an array as value, we skip it
                if (isset($exif[$field]) and ! is_array($exif[$field])) {
                    $key = $tokens[1];
                    if (isset($lang['exif_field_' . $key]) and is_string($lang['exif_field_' . $key])) {
                        $key = $lang['exif_field_' . $key];
                    }
                    $tpl_meta['lines'][$key] = $exif[$field];
                }
            }
        }
        $template->append('metadata', $tpl_meta);
    }
}

if ($conf['show_iptc']) {
    // $conf's own values are only known as mixed -- get_iptc_data() requires
    // a real array<string, string> map, so rebuild it from validated
    // string=>string pairs only (config_default.inc.php documents
    // show_iptc_mapping as such a map).
    $show_iptc_mapping_raw = $conf['show_iptc_mapping'] ?? null;
    $show_iptc_mapping = [];
    if (is_array($show_iptc_mapping_raw)) {
        foreach ($show_iptc_mapping_raw as $iptc_map_key => $iptc_map_value) {
            if (is_string($iptc_map_key) and is_string($iptc_map_value)) {
                $show_iptc_mapping[$iptc_map_key] = $iptc_map_value;
            }
        }
    }

    $iptc = get_iptc_data($picture['current']['src_image']->get_path(), $show_iptc_mapping, ', ');

    if (count($iptc) > 0) {
        $tpl_meta = [
            'TITLE' => l10n('IPTC Metadata'),
            'lines' => [],
        ];

        foreach ($iptc as $field => $value) {
            $key = $field;
            if (isset($lang[$field]) and is_string($lang[$field])) {
                $key = $lang[$field];
            }
            $tpl_meta['lines'][$key] = $value;
        }
        $template->append('metadata', $tpl_meta);
    }
}
