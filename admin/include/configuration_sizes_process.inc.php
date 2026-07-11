<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/** @var array<string, mixed> $conf */
global $conf;
/** @var Template $template */
global $template;
/** @var array<string, mixed> $page */
global $page;

if (! is_webmaster()) {
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
    $value = ! empty($_POST[$field]) ? $_POST[$field] : null;
    $updates[$field] = $value;
}

// $page['errors'] is only known to be array<string, mixed> one level deep;
// narrow the nested value to array<int, string> before passing it by
// reference into save_upload_form_config() (same filter-into-a-fresh-array
// pattern as $pderivatives below), then write the possibly-appended-to
// result back so callers of this include still see the errors.
$page_errors_raw = $page['errors'] ?? null;
/** @var array<int, string> $page_errors */
$page_errors = [];
if (is_array($page_errors_raw)) {
    foreach ($page_errors_raw as $page_error) {
        if (is_string($page_error)) {
            $page_errors[] = $page_error;
        }
    }
}

save_upload_form_config($updates, $page_errors, $errors);

$page['errors'] = $page_errors;

if ($_POST['resize_quality'] < 50 or $_POST['resize_quality'] > 98) {
    $errors['resize_quality'] = '[50..98]';
}

$pderivatives_post = $_POST['d'] ?? null;

// The form posts a nested array keyed by derivative type, e.g.
// d[square][w], d[square][enabled] — every leaf value therefore arrives as
// a plain string. Anything else (missing key, or a tampered field name
// producing a nested array where a scalar is expected) is dropped here so
// the rest of this file can rely on a real, non-mixed shape instead of
// bare-casting raw superglobal data at each point of use.
/** @var array<string, array<string, string|int|bool|null>> $pderivatives */
$pderivatives = [];
if (is_array($pderivatives_post)) {
    foreach ($pderivatives_post as $ptype => $pfields) {
        if (! is_string($ptype) || ! is_array($pfields)) {
            continue;
        }
        $normalized = [];
        foreach ($pfields as $pkey => $pvalue) {
            if (is_string($pkey) && is_string($pvalue)) {
                $normalized[$pkey] = $pvalue;
            }
        }
        $pderivatives[$ptype] = $normalized;
    }
}

// step 1 - sanitize HTML input
foreach ($pderivatives as $type => &$pderivative) {
    if ($pderivative['must_square'] = ($type == IMG_SQUARE ? true : false)) {
        $pderivative['h'] = $pderivative['w'];
        $pderivative['minh'] = $pderivative['minw'] = $pderivative['w'];
        $pderivative['crop'] = 100;
    }
    $pderivative['must_enable'] = ($type == IMG_SQUARE || $type == IMG_THUMB || $type == $conf['derivative_default_size']) ? true : false;
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
//
// $derivative_errors is kept separate from $errors (a flat field =>
// message map) because it's a nested type => ['w' => message, ...] map;
// the two are merged back together below for the 'ferrors' template
// assignment
/** @var array<string, array<string, string>> $derivative_errors */
$derivative_errors = [];
$prev_w = $prev_h = 0;
foreach (ImageStdParams::get_all_types() as $type) {
    $pderivative = $pderivatives[$type];
    if (! $pderivative['enabled']) {
        continue;
    }

    if ($type == IMG_THUMB) {
        $w = intval($pderivative['w']);
        if ($w <= 0) {
            $derivative_errors[$type]['w'] = '>0';
        }

        $h = intval($pderivative['h']);
        if ($h <= 0) {
            $derivative_errors[$type]['h'] = '>0';
        }

        if (max($w, $h) <= $prev_w) {
            $derivative_errors[$type]['w'] = $derivative_errors[$type]['h'] = '>' . $prev_w;
        }
    } else {
        $v = intval($pderivative['w']);
        if ($v <= 0 or $v <= $prev_w) {
            $derivative_errors[$type]['w'] = '>' . $prev_w;
        }

        $v = intval($pderivative['h']);
        if ($v <= 0 or $v <= $prev_h) {
            $derivative_errors[$type]['h'] = '>' . $prev_h;
        }
    }

    if (count($errors) == 0 && count($derivative_errors) == 0) {
        $prev_w = intval($pderivative['w']);
        $prev_h = intval($pderivative['h']);
    }

    $v = intval($pderivative['sharpen']);
    if ($v < 0 || $v > 100) {
        $derivative_errors[$type]['sharpen'] = '[0..100]';
    }
}

// step 3 - save data
if (count($errors) == 0 && count($derivative_errors) == 0) {
    $resize_quality_post = $_POST['resize_quality'] ?? null;
    $resize_quality = is_numeric($resize_quality_post) ? intval($resize_quality_post) : 0;
    $quality_changed = ImageStdParams::$quality != $resize_quality;
    ImageStdParams::$quality = $resize_quality;

    $enabled = ImageStdParams::get_defined_type_map();
    $disabled_raw = safe_unserialize(ImageStdParams::get_disabled_type_map());
    // ImageStdParams persists this map as serialize()d DerivativeParams[]
    // (see ImageStdParams::save_disabled()); unserialize() is only typed
    // mixed by PHP itself, so filter out anything that isn't actually a
    // DerivativeParams instance rather than trusting the blob blindly.
    /** @var array<string, DerivativeParams> $disabled */
    $disabled = [];
    if (is_array($disabled_raw)) {
        foreach ($disabled_raw as $disabled_type => $disabled_params) {
            if (is_string($disabled_type) && $disabled_params instanceof DerivativeParams) {
                $disabled[$disabled_type] = $disabled_params;
            }
        }
    }
    $changed_types = [];

    foreach (ImageStdParams::get_all_types() as $type) {
        $pderivative = $pderivatives[$type];

        if ($pderivative['enabled']) {
            $new_params = new DerivativeParams(
                new SizingParams(
                    [intval($pderivative['w']), intval($pderivative['h'])],
                    round(intval($pderivative['crop']) / 100, 2),
                    [intval($pderivative['minw']), intval($pderivative['minh'])]
                )
            );
            $new_params->sharpen = intval($pderivative['sharpen']);

            ImageStdParams::apply_global($new_params);

            if (isset($enabled[$type])) {
                $old_params = $enabled[$type];
                $same = true;
                if (! size_equals($old_params->sizing->ideal_size, $new_params->sizing->ideal_size)
                    or $old_params->sizing->max_crop != $new_params->sizing->max_crop) {
                    $same = false;
                }

                if ($same
                    and $new_params->sizing->max_crop != 0
                    and ! size_equals($old_params->sizing->min_size, $new_params->sizing->min_size)) {
                    $same = false;
                }

                if ($quality_changed
                    || $new_params->sharpen != $old_params->sharpen) {
                    $same = false;
                }

                if (! $same) {
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
        if (isset($_POST['delete_custom_derivative_' . $custom])) {
            $changed_types[] = $custom;
            unset(ImageStdParams::$custom[$custom]);
        }
    }

    ImageStdParams::set_and_save($enabled_by);
    ImageStdParams::set_and_save_disabled($disabled);

    if ((bool) count($changed_types)) {
        clear_derivative_cache($changed_types);
    }

    $template->assign(
        [
            'save_success' => l10n('Your configuration settings are saved'),
        ]
    );

    pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'config', [
        'config_section' => 'sizes',
    ]);
} else {
    foreach ($original_fields as $field) {
        if (isset($_POST[$field]) && is_string($_POST[$field])) {
            $template->append(
                'sizes',
                [
                    $field => strip_tags($_POST[$field]), // strip_tags prevents from XSS attempt
                ],
                true
            );
        }
    }

    $template->assign('derivatives', $pderivatives);
    $template->assign('ferrors', $errors + $derivative_errors);
    $template->assign('resize_quality', $_POST['resize_quality']);
    $page['sizes_loaded_in_tpl'] = true;
}
