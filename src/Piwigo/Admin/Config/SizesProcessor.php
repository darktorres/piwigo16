<?php

declare(strict_types=1);

namespace Piwigo\Admin\Config;

use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Config\Config;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\Lang;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Image\DerivativeEncoding;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\PermissionService;

final readonly class SizesProcessor
{
    public function __construct(
        private ImageAdminService $imageAdminService,
        private UploadService $uploadService,
        private Util $util,
        private PermissionService $permissionService,
    ) {
    }
    public function process(): void
    {
        if (!$this->permissionService->isWebmaster()) {
            return;
        }

        /** @var array<string, string|array<string, string>> $errors */
        $errors = [];

        $original_fields = [
            'original_resize',
            'original_resize_maxwidth',
            'original_resize_maxheight',
            'original_resize_quality',
        ];

        $updates = [];
        foreach ($original_fields as $field) {
            $updates[$field] = $_POST[$field] ?? null;
        }

        /** @var array<string, mixed> $page */
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        /** @var string[] $pageErrors */
        $pageErrors = is_array($page['errors'] ?? null) ? $page['errors'] : [];
        $this->uploadService->saveUploadFormConfig($updates, $pageErrors, $errors);
        $page['errors'] = $pageErrors;
        $GLOBALS['page'] = $page;

        $rq_raw = $_POST['resize_quality'] ?? null;
        $rq = is_numeric($rq_raw) ? (int) $rq_raw : null;
        if ($rq !== null && ($rq < 50 || $rq > 98)) {
            $errors['resize_quality'] = '[50..98]';
        }

        /** @var array<string, array<string, mixed>> $pderivatives */
        $pderivatives = is_array($_POST['d'] ?? null) ? $_POST['d'] : [];

        // step 1 — sanitize HTML input
        foreach ($pderivatives as $type => &$pderivative) {
            $isSquare = ($type == DerivativeSize::Square->value);
            $pderivative['must_square'] = $isSquare;
            $pderivative['must_enable'] = ($isSquare || $type == DerivativeSize::Thumb->value || $type == Config::derivativeDefaultSize());
            if ($isSquare) {
                $pderivative['h'] = $pderivative['w'];
                $pderivative['minh'] = $pderivative['minw'] = $pderivative['w'];
                $pderivative['crop'] = 100;
            }
            $pderivative['enabled'] = isset($pderivative['enabled']) || $pderivative['must_enable'];

            if (isset($pderivative['crop'])) {
                $pderivative['crop'] = 100;
                $pderivative['minw'] = $pderivative['w'] ?? null;
                $pderivative['minh'] = $pderivative['h'] ?? null;
            } else {
                $pderivative['crop'] = 0;
                $pderivative['minw'] = null;
                $pderivative['minh'] = null;
            }
        }
        unset($pderivative);

        // step 2 — check validity
        $prev_w = $prev_h = 0;
        foreach (ImageStdParams::getAllTypes() as $type) {
            /** @var array<string, mixed> $pderivative */
            $pderivative = $pderivatives[$type] ?? [];
            if (!$pderivative['enabled']) {
                continue;
            }
            if (!is_array($errors[$type] ?? null)) {
                $errors[$type] = [];
            }
            /** @var array<string, string> $typeErrors */
            $typeErrors = $errors[$type];

            if ($type == DerivativeSize::Thumb->value) {
                $w = is_numeric($pderivative['w']) ? (int) $pderivative['w'] : 0;
                if ($w <= 0) {
                    $typeErrors['w'] = '>0';
                }
                $h = is_numeric($pderivative['h']) ? (int) $pderivative['h'] : 0;
                if ($h <= 0) {
                    $typeErrors['h'] = '>0';
                }
                if (max($w, $h) <= $prev_w) {
                    $typeErrors['w'] = $typeErrors['h'] = '>' . $prev_w;
                }
            } else {
                $v = is_numeric($pderivative['w']) ? (int) $pderivative['w'] : 0;
                if ($v <= 0 || $v <= $prev_w) {
                    $typeErrors['w'] = '>' . $prev_w;
                }
                $v = is_numeric($pderivative['h']) ? (int) $pderivative['h'] : 0;
                if ($v <= 0 || $v <= $prev_h) {
                    $typeErrors['h'] = '>' . $prev_h;
                }
            }

            if (count($errors) == 0) {
                $prev_w = is_numeric($pderivative['w']) ? (int) $pderivative['w'] : 0;
                $prev_h = is_numeric($pderivative['h']) ? (int) $pderivative['h'] : 0;
            }

            $v = is_numeric($pderivative['sharpen']) ? (int) $pderivative['sharpen'] : 0;
            if ($v < 0 || $v > 100) {
                $typeErrors['sharpen'] = '[0..100]';
            }
            $errors[$type] = $typeErrors;
        }

        // step 3 — save data
        $tpl = TemplateRegistry::current();
        if (count($errors) == 0) {
            $resize_quality_raw = $_POST['resize_quality'] ?? 0;
            $resize_quality = is_numeric($resize_quality_raw) ? (int) $resize_quality_raw : 0;
            $quality_changed = ImageStdParams::$quality != $resize_quality;
            ImageStdParams::$quality = $resize_quality;

            $enabled = ImageStdParams::getDefinedTypeMap();
            /** @var array<string, DerivativeParams> $disabled */
            $disabled = StringUtil::safeUnserialize(ImageStdParams::getDisabledTypeMap());
            $changed_types = [];

            foreach (ImageStdParams::getAllTypes() as $type) {
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
                            round($pd_crop / 100.0, 2),
                            [$pd_minw, $pd_minh]
                        )
                    );
                    $new_params->sharpen = $pd_sharpen;

                    ImageStdParams::applyGlobal($new_params);

                    if (isset($enabled[$type])) {
                        $old_params = $enabled[$type];
                        $same = true;
                        if (!DerivativeEncoding::sizeEquals($old_params->sizing->ideal_size, $new_params->sizing->ideal_size)
                            || $old_params->sizing->max_crop != $new_params->sizing->max_crop) {
                            $same = false;
                        }
                        if ($same
                            && $new_params->sizing->max_crop != 0
                            && !DerivativeEncoding::sizeEquals($old_params->sizing->min_size ?? [], $new_params->sizing->min_size)) {
                            $same = false;
                        }
                        if ($quality_changed || $new_params->sharpen != $old_params->sharpen) {
                            $same = false;
                        }
                        if (!$same) {
                            $new_params->last_mod_time = time();
                            $changed_types[] = $type;
                        } else {
                            $new_params->last_mod_time = $old_params->last_mod_time;
                        }
                        $enabled[$type] = $new_params;
                    } else {
                        $enabled[$type] = $new_params;
                        unset($disabled[$type]);
                    }
                } else {
                    if (isset($enabled[$type])) {
                        $changed_types[] = $type;
                        $disabled[$type] = $enabled[$type];
                        unset($enabled[$type]);
                    }
                }
            }

            $enabled_by = [];
            foreach (ImageStdParams::getAllTypes() as $type) {
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

            ImageStdParams::setAndSave($enabled_by);
            ImageStdParams::setAndSaveDisabled($disabled);

            if (count($changed_types)) {
                $this->imageAdminService->clearDerivativeCache($changed_types);
            }

            $tpl->assign(['save_success' => Lang::t('Your configuration settings are saved')]);
            $this->util->pwgActivity('system', ActivitySystem::Core, 'config', ['config_section' => 'sizes']);
        } else {
            foreach ($original_fields as $field) {
                if (isset($_POST[$field])) {
                    $tpl->append(
                        'sizes',
                        [$field => strip_tags(is_string($_POST[$field]) ? $_POST[$field] : '')],
                        true
                    );
                }
            }
            $tpl->assign('derivatives', $pderivatives);
            $tpl->assign('ferrors', $errors);
            $rawResizeQuality = $_POST['resize_quality'] ?? '';
            $tpl->assign('resize_quality', is_scalar($rawResizeQuality) ? $rawResizeQuality : '');
            $GLOBALS['page']['sizes_loaded_in_tpl'] = true;
        }
    }
}
