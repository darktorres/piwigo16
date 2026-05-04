<?php

declare(strict_types=1);

use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new \Piwigo\Exception\AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


if (!is_webmaster()) {
    return;
}

$errors = [];

// original resize
$original_fields = [
  'original_resize',
  'original_resize_maxwidth',
  'original_resize_maxheight',
  'original_resize_quality',
  ];

$updates = [];

foreach ($original_fields as $field) {
    $value = !empty($_POST[$field]) ? $_POST[$field] : null;
    $updates[$field] = $value;
}

save_upload_form_config($updates, $page['errors'], $errors);

if ($_POST['resize_quality'] < 50 or $_POST['resize_quality'] > 98) {
    $errors['resize_quality'] = '[50..98]';
}

/** @var array<string, array<string, mixed>> $pderivatives */
$pderivatives = is_array($_POST['d'] ?? null) ? $_POST['d'] : [];

// step 1 - sanitize HTML input
foreach ($pderivatives as $type => &$pderivative) {
    if ($pderivative['must_square'] = ($type == IMG_SQUARE ? true : false)) {
        $pderivative['h'] = $pderivative['w'];
        $pderivative['minh'] = $pderivative['minw'] = $pderivative['w'];
        $pderivative['crop'] = 100;
    }
    $pderivative['must_enable'] = ($type == IMG_SQUARE || $type == IMG_THUMB || $type == \Piwigo\Config\Config::derivativeDefaultSize()) ? true : false;
    $pderivative['enabled'] = isset($pderivative['enabled']) || $pderivative['must_enable'] ? true : false;

    if (isset($pderivative['crop'])) {
        $pderivative['crop'] = 100;
        $pderivative['minw'] = $pderivative['w'];
        $pderivative['minh'] = $pderivative['h'];
    } else {
        $pderivative['crop'] = 0;
        $pderivative['minw'] = null;
        $pderivative['minh'] = null;
    }
}
unset($pderivative);

// step 2 - check validity
$prev_w = $prev_h = 0;
foreach (ImageStdParams::get_all_types() as $type) {
    /** @var array<string, mixed> $pderivative */
    $pderivative = $pderivatives[$type] ?? [];
    if (!$pderivative['enabled']) {
        continue;
    }
    if (!is_array($errors[$type] ?? null)) {
        $errors[$type] = [];
    }

    if ($type == IMG_THUMB) {
        $w = is_numeric($pderivative['w']) ? (int) $pderivative['w'] : 0;
        if ($w <= 0) {
            $errors[$type]['w'] = '>0';
        }

        $h = is_numeric($pderivative['h']) ? (int) $pderivative['h'] : 0;
        if ($h <= 0) {
            $errors[$type]['h'] = '>0';
        }

        if (max($w, $h) <= $prev_w) {
            $errors[$type]['w'] = $errors[$type]['h'] = '>'.$prev_w;
        }
    } else {
        $v = is_numeric($pderivative['w']) ? (int) $pderivative['w'] : 0;
        if ($v <= 0 or $v <= $prev_w) {
            $errors[$type]['w'] = '>'.$prev_w;
        }

        $v = is_numeric($pderivative['h']) ? (int) $pderivative['h'] : 0;
        if ($v <= 0 or $v <= $prev_h) {
            $errors[$type]['h'] = '>'.$prev_h;
        }
    }

    if (count($errors) == 0) {
        $prev_w = is_numeric($pderivative['w']) ? (int) $pderivative['w'] : 0;
        $prev_h = is_numeric($pderivative['h']) ? (int) $pderivative['h'] : 0;
    }

    $v = is_numeric($pderivative['sharpen']) ? (int) $pderivative['sharpen'] : 0;
    if ($v < 0 || $v > 100) {
        $errors[$type]['sharpen'] = '[0..100]';
    }
}

// step 3 - save data
if (count($errors) == 0) {
    $resize_quality_raw = $_POST['resize_quality'] ?? 0;
    $resize_quality = is_numeric($resize_quality_raw) ? (int) $resize_quality_raw : 0;
    $quality_changed = ImageStdParams::$quality != $resize_quality;
    ImageStdParams::$quality = $resize_quality;

    $enabled = ImageStdParams::get_defined_type_map();
    $disabled = safe_unserialize(ImageStdParams::get_disabled_type_map());
    $changed_types = [];

    foreach (ImageStdParams::get_all_types() as $type) {
        /** @var array<string, mixed> $pderivative */
        $pderivative = $pderivatives[$type] ?? [];

        if ($pderivative['enabled']) {
            $pd_w = is_numeric($pderivative['w']) ? (int) $pderivative['w'] : 0;
            $pd_h = is_numeric($pderivative['h']) ? (int) $pderivative['h'] : 0;
            $pd_crop = is_numeric($pderivative['crop']) ? (float) $pderivative['crop'] : 0.0;
            $pd_minw = is_numeric($pderivative['minw']) ? (int) $pderivative['minw'] : 0;
            $pd_minh = is_numeric($pderivative['minh']) ? (int) $pderivative['minh'] : 0;
            $pd_sharpen = is_numeric($pderivative['sharpen']) ? (int) $pderivative['sharpen'] : 0;
            $new_params = new DerivativeParams(
                new SizingParams(
                    [$pd_w, $pd_h],
                    round($pd_crop / 100, 2),
                    [$pd_minw, $pd_minh]
                )
            );
            $new_params->sharpen = $pd_sharpen;

            ImageStdParams::apply_global($new_params);

            if (isset($enabled[$type])) {
                $old_params = $enabled[$type];
                $same = true;
                if (!size_equals($old_params->sizing->ideal_size, $new_params->sizing->ideal_size)
                    or $old_params->sizing->max_crop != $new_params->sizing->max_crop) {
                    $same = false;
                }

                if ($same
                    and $new_params->sizing->max_crop != 0
                    and !size_equals($old_params->sizing->min_size, $new_params->sizing->min_size)) {
                    $same = false;
                }

                if ($quality_changed
                    || $new_params->sharpen != $old_params->sharpen) {
                    $same = false;
                }

                if (!$same) {
                    $new_params->last_mod_time = time();
                    $changed_types[] = $type;
                } else {
                    $new_params->last_mod_time = $old_params->last_mod_time;
                }
                $enabled[$type] = $new_params;
            } else {// now enabled, before was disabled
                $enabled[$type] = $new_params;
                unset($disabled[$type]);
            }
        } else {// disabled
            if (isset($enabled[$type])) {// now disabled, before was enabled
                $changed_types[] = $type;
                $disabled[$type] = $enabled[$type];
                unset($enabled[$type]);
            }
        }
    }

    $enabled_by = []; // keys ordered by all types
    foreach (ImageStdParams::get_all_types() as $type) {
        if (isset($enabled[$type])) {
            $enabled_by[$type] = $enabled[$type];
        }
    }

    foreach (array_keys(ImageStdParams::$custom) as $custom) {
        if (isset($_POST['delete_custom_derivative_'.$custom])) {
            $changed_types[] = $custom;
            unset(ImageStdParams::$custom[$custom]);
        }
    }

    ImageStdParams::set_and_save($enabled_by);
    $disabled_params = array_filter($disabled, fn ($v) => $v instanceof DerivativeParams);
    ImageStdParams::set_and_save_disabled($disabled_params);

    if (count($changed_types)) {
        clear_derivative_cache($changed_types);
    }

    $template->assign(
        [
        'save_success' => l10n('Your configuration settings are saved'),
    ]
    );

    pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'config', ['config_section' => 'sizes']);
} else {
    foreach ($original_fields as $field) {
        if (isset($_POST[$field])) {
            $template->append(
                'sizes',
                [
                $field => strip_tags(is_scalar($_POST[$field]) ? (string) $_POST[$field] : ''), // strip_tags prevents from XSS attempt
                ],
                true
            );
        }
    }

    $template->assign('derivatives', $pderivatives);
    $template->assign('ferrors', $errors);
    $template->assign('resize_quality', $_POST['resize_quality']);
    $page['sizes_loaded_in_tpl'] = true;
}
