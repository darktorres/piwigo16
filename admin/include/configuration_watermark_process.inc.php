<?php

declare(strict_types=1);

use Piwigo\Image\ImageStdParams;
use Piwigo\Image\WatermarkParams;

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

/** @param string[] $list */
function get_watermark_filename(array $list, string $candidate, int $step = 0): string
{
    $change_name = $candidate;
    if ($step != 0) {
        $change_name .= '-'.$step;
    }
    if (in_array($change_name, $list)) {
        return get_watermark_filename($list, $candidate, $step + 1);
    }
    return $change_name.'.png';
}

$errors = [];
/** @var array<string, mixed> $pwatermark */
$pwatermark = is_array($_POST['w'] ?? null) ? $_POST['w'] : [];

// step 0 - manage upload if any
$watermarkImage = is_array($_FILES['watermarkImage'] ?? null) ? $_FILES['watermarkImage'] : [];
if (!empty($watermarkImage['tmp_name'])) {
    $tmp_name = is_scalar($watermarkImage['tmp_name']) ? (string) $watermarkImage['tmp_name'] : '';
    [$width, $height, $type] = getimagesize($tmp_name) ?: [0, 0, 0];
    if (IMAGETYPE_PNG != $type) {
        $errors['watermarkImage'] = sprintf(
            l10n('Allowed file types: %s.'),
            'PNG'
        );
    } else {
        $upload_dir = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'watermarks';
        if (mkgetdir($upload_dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
            // file name may include exotic chars like single quote, we need a safe name
            $wm_file_name = is_scalar($watermarkImage['name']) ? (string) $watermarkImage['name'] : '';
            $new_name = str2url(get_filename_wo_extension($wm_file_name));

            // we need existing watermarks to avoid overwritting one
            $watermark_files = [];
            if (($glob = glob(PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'watermarks/*.png')) !== false) {
                foreach ($glob as $file) {
                    $watermark_files[] = get_filename_wo_extension(
                        substr($file, strlen(PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'watermarks/'))
                    );
                }
            }

            $file_path = $upload_dir.'/'.get_watermark_filename($watermark_files, $new_name);

            if (move_uploaded_file($tmp_name, $file_path)) {
                $pwatermark['file'] = substr($file_path, strlen(PHPWG_ROOT_PATH));
            } else {
                \Piwigo\Core\PageState::current()->addError($errors['watermarkImage'] = "$file_path " .l10n('no write access'));
            }
        } else {
            \Piwigo\Core\PageState::current()->addError($errors['watermarkImage'] = sprintf(l10n('Add write access to the "%s" directory'), $upload_dir));
        }
    }
}

// step 1 - sanitize HTML input
switch ($pwatermark['position']) {
    case 'topleft':
        {
            $pwatermark['xpos'] = 0;
            $pwatermark['ypos'] = 0;
            break;
        }
    case 'topright':
        {
            $pwatermark['xpos'] = 100;
            $pwatermark['ypos'] = 0;
            break;
        }
    case 'middle':
        {
            $pwatermark['xpos'] = 50;
            $pwatermark['ypos'] = 50;
            break;
        }
    case 'bottomleft':
        {
            $pwatermark['xpos'] = 0;
            $pwatermark['ypos'] = 100;
            break;
        }
    case 'bottomright':
        {
            $pwatermark['xpos'] = 100;
            $pwatermark['ypos'] = 100;
            break;
        }
}

// step 2 - check validity
$v = is_numeric($pwatermark['xpos']) ? (int) $pwatermark['xpos'] : 0;
if ($v < 0 or $v > 100) {
    $errors['watermark']['xpos'] = '[0..100]';
}

$v = is_numeric($pwatermark['ypos']) ? (int) $pwatermark['ypos'] : 0;
if ($v < 0 or $v > 100) {
    $errors['watermark']['ypos'] = '[0..100]';
}

$v = is_numeric($pwatermark['opacity']) ? (int) $pwatermark['opacity'] : 0;
if ($v <= 0 or $v > 100) {
    $errors['watermark']['opacity'] = '(0..100]';
}

// step 3 - save data
if (count($errors) == 0) {
    $watermark = new WatermarkParams();
    $watermark->file = is_scalar($pwatermark['file']) ? (string) $pwatermark['file'] : '';
    $watermark->xpos = is_numeric($pwatermark['xpos']) ? (int) $pwatermark['xpos'] : 0;
    $watermark->ypos = is_numeric($pwatermark['ypos']) ? (int) $pwatermark['ypos'] : 0;
    $watermark->xrepeat = is_numeric($pwatermark['xrepeat']) ? (int) $pwatermark['xrepeat'] : 0;
    $watermark->yrepeat = is_numeric($pwatermark['yrepeat']) ? (int) $pwatermark['yrepeat'] : 0;
    $watermark->opacity = is_numeric($pwatermark['opacity']) ? (int) $pwatermark['opacity'] : 0;
    $watermark->min_size = [is_numeric($pwatermark['minw']) ? (int) $pwatermark['minw'] : 0, is_numeric($pwatermark['minh']) ? (int) $pwatermark['minh'] : 0];

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
        if (!$changed and $params->use_watermark) {
            $changed = $watermark_changed;
        }
        if (!$changed and $params->use_watermark) {
            // if thresholds change and before/after the threshold is lower than the corresponding derivative side -> some derivatives might switch the watermark
            $changed |= $watermark->min_size[0] != $old_watermark->min_size[0] and ($watermark->min_size[0] < $params->max_width() or $old_watermark->min_size[0] < $params->max_width());
            $changed |= $watermark->min_size[1] != $old_watermark->min_size[1] and ($watermark->min_size[1] < $params->max_height() or $old_watermark->min_size[1] < $params->max_height());
        }

        if ($changed) {
            $params->last_mod_time = time();
            $changed_types[] = $type;
        }
    }

    ImageStdParams::save();

    if (count($changed_types)) {
        clear_derivative_cache($changed_types);
    }

    $template->assign(
        [
        'save_success' => l10n('Your configuration settings are saved'),
    ]
    );

    pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'config', ['config_section' => 'watermark']);
} else {
    $template->assign('watermark', $pwatermark);
    $template->assign('ferrors', $errors);
}
