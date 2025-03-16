<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\functions_upload;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\derivative_params;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\DerivativeParams;
use Piwigo\inc\functions;
use Piwigo\inc\functions_user;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\SizingParams;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

if (! functions_user::is_webmaster()) {
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

functions_upload::save_upload_form_config($updates, $page['errors'], $errors);

if ($_POST['resize_quality'] < 50 or $_POST['resize_quality'] > 98) {
    $errors['resize_quality'] = '[50..98]';
}

$pderivatives = $_POST['d'];

// step 1 - sanitize HTML input
foreach ($pderivatives as $type => &$pderivative) {
    if ($pderivative['must_square'] = ($type == derivative_std_params::IMG_SQUARE ? true : false)) {
        $pderivative['h'] = $pderivative['w'];
        $pderivative['minh'] = $pderivative['minw'] = $pderivative['w'];
        $pderivative['crop'] = 100;
    }
    $pderivative['must_enable'] = ($type == derivative_std_params::IMG_SQUARE || $type == derivative_std_params::IMG_THUMB || $type == $conf['derivative_default_size']) ? true : false;
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
    $pderivative = $pderivatives[$type];
    if (! $pderivative['enabled']) {
        continue;
    }

    if ($type == derivative_std_params::IMG_THUMB) {
        $w = intval($pderivative['w']);
        if ($w <= 0) {
            $errors[$type]['w'] = '>0';
        }

        $h = intval($pderivative['h']);
        if ($h <= 0) {
            $errors[$type]['h'] = '>0';
        }

        if (max($w, $h) <= $prev_w) {
            $errors[$type]['w'] = $errors[$type]['h'] = '>' . $prev_w;
        }
    } else {
        $v = intval($pderivative['w']);
        if ($v <= 0 or $v <= $prev_w) {
            $errors[$type]['w'] = '>' . $prev_w;
        }

        $v = intval($pderivative['h']);
        if ($v <= 0 or $v <= $prev_h) {
            $errors[$type]['h'] = '>' . $prev_h;
        }
    }

    if (count($errors) == 0) {
        $prev_w = intval($pderivative['w']);
        $prev_h = intval($pderivative['h']);
    }

    $v = intval($pderivative['sharpen']);
    if ($v < 0 || $v > 100) {
        $errors[$type]['sharpen'] = '[0..100]';
    }
}

// step 3 - save data
if (count($errors) == 0) {
    $quality_changed = ImageStdParams::$quality != intval($_POST['resize_quality']);
    ImageStdParams::$quality = intval($_POST['resize_quality']);

    $enabled = ImageStdParams::get_defined_type_map();
    $disabled = @unserialize(@$conf['disabled_derivatives']);
    if ($disabled === false) {
        $disabled = [];
    }
    $changed_types = [];

    foreach (ImageStdParams::get_all_types() as $type) {
        $pderivative = $pderivatives[$type];

        if ($pderivative['enabled']) {
            $new_params = new DerivativeParams(
                new SizingParams(
                    [intval($pderivative['w']), intval($pderivative['h'])],
                    round($pderivative['crop'] / 100, 2),
                    [intval($pderivative['minw']), intval($pderivative['minh'])]
                )
            );
            $new_params->sharpen = intval($pderivative['sharpen']);

            ImageStdParams::apply_global($new_params);

            if (isset($enabled[$type])) {
                $old_params = $enabled[$type];
                $same = true;
                if (! derivative_params::size_equals($old_params->sizing->ideal_size, $new_params->sizing->ideal_size)
                    or $old_params->sizing->max_crop != $new_params->sizing->max_crop) {
                    $same = false;
                }

                if ($same
                    and $new_params->sizing->max_crop != 0
                    and ! derivative_params::size_equals($old_params->sizing->min_size, $new_params->sizing->min_size)) {
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
    if (count($disabled) == 0) {
        $query = 'DELETE FROM ' . CONFIG_TABLE . ' WHERE param = \'disabled_derivatives\'';
        functions_mysqli::pwg_query($query);
    } else {
        functions::conf_update_param('disabled_derivatives', addslashes(serialize($disabled)));
    }
    $conf['disabled_derivatives'] = serialize($disabled);

    if (count($changed_types)) {
        functions_admin::clear_derivative_cache($changed_types);
    }

    $page['infos'][] = functions::l10n('Your configuration settings are saved');
    functions::pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'config', [
        'config_section' => 'sizes',
    ]);
} else {
    foreach ($original_fields as $field) {
        if (isset($_POST[$field])) {
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
    $template->assign('ferrors', $errors);
    $template->assign('resize_quality', $_POST['resize_quality']);
    $page['sizes_loaded_in_tpl'] = true;
}
