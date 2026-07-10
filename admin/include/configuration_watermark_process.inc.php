<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Image\ImageStdParams;
use Piwigo\Image\WatermarkParams;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/** @var \Template $template */
global $template;

// $page['errors'] is always initialized to [] by admin/configuration.php
// (the only caller that include()s this file), but that isn't visible
// across the include() boundary -- narrow once here so the appends below
// type-check.
/** @var array<string, mixed> $page */
global $page;
if (! is_array($page['errors'] ?? null)) {
    $page['errors'] = [];
}

if (! is_webmaster()) {
    return;
}

/**
 * @param array<int, string> $list
 */
function get_watermark_filename(array $list, string $candidate, int $step = 0): string
{
    global $change_name;
    $change_name = $candidate;
    if ($step != 0) {
        $change_name .= '-' . $step;
    }
    if (in_array($change_name, $list)) {
        return get_watermark_filename($list, $candidate, $step + 1);
    }
    return $change_name . '.png';
}

$errors = [];
$pwatermark_post = $_POST['w'] ?? null;

// The form posts a flat array w[key]=value (see configuration_watermark.tpl)
// where every leaf arrives as a plain string; normalize into a concrete
// shape so the rest of this file can rely on real types instead of
// bare-casting raw superglobal data at each point of use.
/** @var array<string, string> $pwatermark */
$pwatermark = [];
if (is_array($pwatermark_post)) {
    foreach ($pwatermark_post as $pkey => $pvalue) {
        if (is_string($pkey) && is_string($pvalue)) {
            $pwatermark[$pkey] = $pvalue;
        }
    }
}

// step 0 - manage upload if any
$watermark_upload = $_FILES['watermarkImage'] ?? null;
$watermark_tmp_name = null;
$watermark_upload_name = null;
if (is_array($watermark_upload)) {
    if (isset($watermark_upload['tmp_name']) && is_string($watermark_upload['tmp_name'])) {
        $watermark_tmp_name = $watermark_upload['tmp_name'];
    }
    if (isset($watermark_upload['name']) && is_string($watermark_upload['name'])) {
        $watermark_upload_name = $watermark_upload['name'];
    }
}

if (! empty($watermark_tmp_name)) {
    $image_size = getimagesize($watermark_tmp_name);
    $type = $image_size === false ? false : $image_size[2];
    if ($type != IMAGETYPE_PNG) {
        $errors['watermarkImage'] = sprintf(
            l10n('Allowed file types: %s.'),
            'PNG'
        );
    } else {
        $upload_dir = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks';
        if (mkgetdir($upload_dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
            // file name may include exotic chars like single quote, we need a safe name
            $new_name = str2url(get_filename_wo_extension($watermark_upload_name ?? ''));

            // we need existing watermarks to avoid overwritting one
            $watermark_files = [];
            if (($glob = glob(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks/*.png')) !== false) {
                foreach ($glob as $file) {
                    $watermark_files[] = get_filename_wo_extension(
                        substr($file, strlen(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks/'))
                    );
                }
            }

            $file_path = $upload_dir . '/' . get_watermark_filename($watermark_files, $new_name);

            if (move_uploaded_file($watermark_tmp_name, $file_path)) {
                $pwatermark['file'] = substr($file_path, strlen(PHPWG_ROOT_PATH));
            } else {
                $page['errors'][] = $errors['watermarkImage'] = "{$file_path} " . l10n('no write access');
            }
        } else {
            $page['errors'][] = $errors['watermarkImage'] = sprintf(l10n('Add write access to the "%s" directory'), $upload_dir);
        }
    }
}

// step 1 - sanitize HTML input
// $pwatermark is declared array<string, string> above; assign string
// literals here (not int) so that promise holds for every key, not just
// the ones read via intval() below -- an int write here would otherwise
// widen PHPStan's inferred value type for the whole array to int|string.
switch ($pwatermark['position']) {
    case 'topleft':

        $pwatermark['xpos'] = '0';
        $pwatermark['ypos'] = '0';
        break;

    case 'topright':

        $pwatermark['xpos'] = '100';
        $pwatermark['ypos'] = '0';
        break;

    case 'middle':

        $pwatermark['xpos'] = '50';
        $pwatermark['ypos'] = '50';
        break;

    case 'bottomleft':

        $pwatermark['xpos'] = '0';
        $pwatermark['ypos'] = '100';
        break;

    case 'bottomright':

        $pwatermark['xpos'] = '100';
        $pwatermark['ypos'] = '100';
        break;

}

// step 2 - check validity
// Accumulate into a local array and only assign it into $errors['watermark']
// if non-empty, matching this file's original auto-vivification behavior --
// pre-creating $errors['watermark'] unconditionally would make count($errors)
// never 0, permanently skipping "step 3 - save data" below. (xpos/ypos come
// from raw user input when position=custom -- see configuration_watermark.tpl
// -- so out-of-range values are a real, reachable case, not dead code.)
$watermark_errors = [];
$v = intval($pwatermark['xpos']);
if ($v < 0 or $v > 100) {
    $watermark_errors['xpos'] = '[0..100]';
}

$v = intval($pwatermark['ypos']);
if ($v < 0 or $v > 100) {
    $watermark_errors['ypos'] = '[0..100]';
}

$v = intval($pwatermark['opacity']);
if ($v <= 0 or $v > 100) {
    $watermark_errors['opacity'] = '(0..100]';
}

if ($watermark_errors !== []) {
    $errors['watermark'] = $watermark_errors;
}

// step 3 - save data
if (count($errors) == 0) {
    $watermark = new WatermarkParams();
    $watermark->file = $pwatermark['file'];
    $watermark->xpos = intval($pwatermark['xpos']);
    $watermark->ypos = intval($pwatermark['ypos']);
    $watermark->xrepeat = intval($pwatermark['xrepeat']);
    $watermark->yrepeat = intval($pwatermark['yrepeat']);
    $watermark->opacity = intval($pwatermark['opacity']);
    $watermark->min_size = [intval($pwatermark['minw']), intval($pwatermark['minh'])];

    $old_watermark = ImageStdParams::get_watermark();
    $watermark_changed =
      $watermark->file != $old_watermark->file
      || $watermark->xpos != $old_watermark->xpos
      || $watermark->ypos != $old_watermark->ypos
      || $watermark->xrepeat != $old_watermark->xrepeat
      || $watermark->yrepeat != $old_watermark->yrepeat
      || $watermark->opacity != $old_watermark->opacity;

    // save the new watermark configuration
    ImageStdParams::set_watermark($watermark);

    // do we have to regenerate the derivatives (and which types)?
    $changed_types = [];

    foreach (ImageStdParams::get_defined_type_map() as $type => $params) {
        $old_use_watermark = $params->use_watermark;
        ImageStdParams::apply_global($params);

        $changed = $params->use_watermark != $old_use_watermark;
        if (! $changed and $params->use_watermark) {
            $changed = $watermark_changed;
        }
        if (! $changed and $params->use_watermark) {
            // if thresholds change and before/after the threshold is lower than the corresponding derivative side -> some derivatives might switch the watermark
            (bool) ($changed |= $watermark->min_size[0] != $old_watermark->min_size[0]) and ($watermark->min_size[0] < $params->max_width() or $old_watermark->min_size[0] < $params->max_width());
            (bool) ($changed |= $watermark->min_size[1] != $old_watermark->min_size[1]) and ($watermark->min_size[1] < $params->max_height() or $old_watermark->min_size[1] < $params->max_height());
        }

        if ((bool) $changed) {
            $params->last_mod_time = time();
            $changed_types[] = $type;
        }
    }

    ImageStdParams::save();

    if ((bool) count($changed_types)) {
        clear_derivative_cache($changed_types);
    }

    $template->assign(
        [
            'save_success' => l10n('Your configuration settings are saved'),
        ]
    );

    pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'config', [
        'config_section' => 'watermark',
    ]);
} else {
    $template->assign('watermark', $pwatermark);
    $template->assign('ferrors', $errors);
}
