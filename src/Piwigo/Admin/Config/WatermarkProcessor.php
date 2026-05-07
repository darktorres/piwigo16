<?php

declare(strict_types=1);

namespace Piwigo\Admin\Config;

use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\WatermarkParams;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\PermissionService;

final class WatermarkProcessor
{
    public function process(): void
    {
        if (!PermissionService::get()->isWebmaster()) {
            return;
        }

        $errors = [];
        /** @var array<string, mixed> $pwatermark */
        $pwatermark = is_array($_POST['w'] ?? null) ? $_POST['w'] : [];

        // step 0 — manage upload if any
        $watermarkImage = is_array($_FILES['watermarkImage'] ?? null) ? $_FILES['watermarkImage'] : [];
        if (!empty($watermarkImage['tmp_name'])) {
            $tmp_name = is_scalar($watermarkImage['tmp_name']) ? (string) $watermarkImage['tmp_name'] : '';
            [$width, $height, $type] = getimagesize($tmp_name) ?: [0, 0, 0];
            if (IMAGETYPE_PNG != $type) {
                $errors['watermarkImage'] = sprintf(l10n('Allowed file types: %s.'), 'PNG');
            } else {
                $upload_dir = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks';
                if (mkgetdir($upload_dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
                    $wm_file_name = is_scalar($watermarkImage['name']) ? (string) $watermarkImage['name'] : '';
                    $new_name = str2url(get_filename_wo_extension($wm_file_name));

                    $watermark_files = [];
                    if (($glob = glob(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks/*.png')) !== false) {
                        foreach ($glob as $file) {
                            $watermark_files[] = get_filename_wo_extension(
                                substr($file, strlen(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks/'))
                            );
                        }
                    }

                    $file_path = $upload_dir . '/' . self::getWatermarkFilename($watermark_files, $new_name);

                    $wmStream = fopen($tmp_name, 'rb');
                    if ($wmStream !== false) {
                        StorageRegistry::disk('watermarks')->writeStream(
                            basename($file_path),
                            $wmStream
                        );
                        fclose($wmStream);
                        $pwatermark['file'] = substr($file_path, strlen(PHPWG_ROOT_PATH));
                    } else {
                        PageState::current()->addError($errors['watermarkImage'] = "$file_path " . l10n('no write access'));
                    }
                } else {
                    PageState::current()->addError($errors['watermarkImage'] = sprintf(l10n('Add write access to the "%s" directory'), $upload_dir));
                }
            }
        }

        // step 1 — sanitize HTML input
        $position = is_scalar($pwatermark['position'] ?? null) ? (string) $pwatermark['position'] : '';
        switch ($position) {
            case 'topleft':
                $pwatermark['xpos'] = 0;
                $pwatermark['ypos'] = 0;
                break;
            case 'topright':
                $pwatermark['xpos'] = 100;
                $pwatermark['ypos'] = 0;
                break;
            case 'middle':
                $pwatermark['xpos'] = 50;
                $pwatermark['ypos'] = 50;
                break;
            case 'bottomleft':
                $pwatermark['xpos'] = 0;
                $pwatermark['ypos'] = 100;
                break;
            case 'bottomright':
                $pwatermark['xpos'] = 100;
                $pwatermark['ypos'] = 100;
                break;
        }

        // step 2 — check validity
        $v = is_numeric($pwatermark['xpos'] ?? null) ? (int) $pwatermark['xpos'] : 0;
        if ($v < 0 || $v > 100) {
            $errors['watermark']['xpos'] = '[0..100]';
        }

        $v = is_numeric($pwatermark['ypos'] ?? null) ? (int) $pwatermark['ypos'] : 0;
        if ($v < 0 || $v > 100) {
            $errors['watermark']['ypos'] = '[0..100]';
        }

        $v = is_numeric($pwatermark['opacity'] ?? null) ? (int) $pwatermark['opacity'] : 0;
        if ($v <= 0 || $v > 100) {
            $errors['watermark']['opacity'] = '(0..100]';
        }

        // step 3 — save data
        $tpl = TemplateRegistry::current();
        if (count($errors) == 0) {
            $watermark = new WatermarkParams();
            $watermark->file = is_scalar($pwatermark['file'] ?? null) ? (string) $pwatermark['file'] : '';
            $watermark->xpos = is_numeric($pwatermark['xpos'] ?? null) ? (int) $pwatermark['xpos'] : 0;
            $watermark->ypos = is_numeric($pwatermark['ypos'] ?? null) ? (int) $pwatermark['ypos'] : 0;
            $watermark->xrepeat = is_numeric($pwatermark['xrepeat'] ?? null) ? (int) $pwatermark['xrepeat'] : 0;
            $watermark->yrepeat = is_numeric($pwatermark['yrepeat'] ?? null) ? (int) $pwatermark['yrepeat'] : 0;
            $watermark->opacity = is_numeric($pwatermark['opacity'] ?? null) ? (int) $pwatermark['opacity'] : 0;
            $watermark->min_size = [
                is_numeric($pwatermark['minw'] ?? null) ? (int) $pwatermark['minw'] : 0,
                is_numeric($pwatermark['minh'] ?? null) ? (int) $pwatermark['minh'] : 0,
            ];

            $old_watermark = ImageStdParams::getWatermark();
            $watermark_changed =
                $watermark->file != $old_watermark->file
                || $watermark->xpos != $old_watermark->xpos
                || $watermark->ypos != $old_watermark->ypos
                || $watermark->xrepeat != $old_watermark->xrepeat
                || $watermark->yrepeat != $old_watermark->yrepeat
                || $watermark->opacity != $old_watermark->opacity;

            ImageStdParams::setWatermark($watermark);

            $changed_types = [];
            foreach (ImageStdParams::getDefinedTypeMap() as $type => $params) {
                $old_use_watermark = $params->use_watermark;
                ImageStdParams::applyGlobal($params);

                $changed = $params->use_watermark != $old_use_watermark;
                if (!$changed && $params->use_watermark) {
                    $changed = $watermark_changed;
                }
                if (!$changed && $params->use_watermark) {
                    $changed |= $watermark->min_size[0] != $old_watermark->min_size[0] && ($watermark->min_size[0] < $params->maxWidth() || $old_watermark->min_size[0] < $params->maxWidth());
                    $changed |= $watermark->min_size[1] != $old_watermark->min_size[1] && ($watermark->min_size[1] < $params->maxHeight() || $old_watermark->min_size[1] < $params->maxHeight());
                }

                if ($changed) {
                    $params->last_mod_time = time();
                    $changed_types[] = $type;
                }
            }

            ImageStdParams::save();

            if (count($changed_types)) {
                ServiceLocator::get(ImageAdminService::class)->clearDerivativeCache($changed_types);
            }

            $tpl->assign(['save_success' => l10n('Your configuration settings are saved')]);
            pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'config', ['config_section' => 'watermark']);
        } else {
            $tpl->assign('watermark', $pwatermark);
            $tpl->assign('ferrors', $errors);
        }
    }

    /** @param string[] $list */
    private static function getWatermarkFilename(array $list, string $candidate, int $step = 0): string
    {
        $change_name = $candidate;
        if ($step != 0) {
            $change_name .= '-' . $step;
        }
        if (in_array($change_name, $list)) {
            return self::getWatermarkFilename($list, $candidate, $step + 1);
        }
        return $change_name . '.png';
    }
}
