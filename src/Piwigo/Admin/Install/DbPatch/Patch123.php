<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install\DbPatch;

use Doctrine\DBAL\Connection;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;

/**
 * Former install/db/123-database.php (P23 sub-batch 8g-2) -- the 2.3
 * resize-settings to 2.4 derivative-settings conversion. IMG_THUMB/
 * IMG_XXSMALL/IMG_MEDIUM became ImageStdParams class constants (the 8f-5
 * mapping the frozen file itself relied on via defined()-guarded
 * defines); the $conf_orig swap operates on the true global.
 */
final class Patch123 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '123';
    }

    #[\Override]
    public function description(): string
    {
        return 'convert 2.3 resize settings into 2.4 derivative settings';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        $configService = \Piwigo\Config\CurrentConfigService::get();
        $configService->loadConfFromDb();
        $dbconf = \Piwigo\Config\Config::all();

        // Config::all() is array<string, mixed> -- DB-loaded config values
        // are always array|scalar|null in practice (ConfigService::decodeScalar()),
        // this just makes that fact visible to confUpdateParam()'s stricter,
        // typed $value parameter.
        $asConfigValue = static fn (mixed $v): array|bool|float|int|string|null => is_array($v) || is_scalar($v) || $v === null ? $v : null;

        //
        // Piwigo 2.3 "HD resize" settings become "original resize" settings in Piwigo 2.4
        //

        if ((bool) $dbconf['upload_form_hd_keep']) {
            if ((bool) $dbconf['upload_form_hd_resize']) {
                $configService->confUpdateParam('original_resize', 'true');
                $configService->confUpdateParam('original_resize_maxwidth', $asConfigValue($dbconf['upload_form_hd_maxwidth']));
                $configService->confUpdateParam('original_resize_maxheight', $asConfigValue($dbconf['upload_form_hd_maxheight']));
                $configService->confUpdateParam('original_resize_quality', $asConfigValue($dbconf['upload_form_hd_quality']));
            }
        } else {
            // The user has decided to remove the high quality. In Piwigo 2.4, this
            // setting does not exists anymore, but we can simulate it by an original
            // resize with 2.3 websize dimensions
            $configService->confUpdateParam('original_resize', 'true');

            $configService->confUpdateParam(
                'original_resize_maxwidth',
                is_numeric($dbconf['upload_form_websize_maxwidth']) ? $dbconf['upload_form_websize_maxwidth'] : 800
            );

            $configService->confUpdateParam(
                'original_resize_maxheight',
                is_numeric($dbconf['upload_form_websize_maxheight']) ? $dbconf['upload_form_websize_maxheight'] : 600
            );

            $configService->confUpdateParam('original_resize_quality', $asConfigValue($dbconf['upload_form_hd_quality']));
        }

        $types = ImageStdParams::get_default_sizes();

        //
        // Piwigo 2.3 "thumbnail" becomes "thumb" size in Piwigo 2.4
        //

        $thumb_width_min = 128; // the default value in Piwigo 2.3
        $thumb_width_max = 300; // slightly bigger than XXS default maxwidth
        $thumb_height_min = 96; // the default value in Piwigo 2.3
        $thumb_height_max = 300; // slightly bigger than XXS default maxheight

        $thumb_is_square = false;
        if ((bool) $dbconf['upload_form_thumb_crop']) {
            $maxwidth = is_scalar($dbconf['upload_form_thumb_maxwidth']) ? (string) $dbconf['upload_form_thumb_maxwidth'] : '';
            $maxheight = is_scalar($dbconf['upload_form_thumb_maxheight']) ? (string) $dbconf['upload_form_thumb_maxheight'] : '';
            if ($maxwidth === $maxheight) {
                $thumb_is_square = true;
            }
        }

        if ($dbconf['upload_form_thumb_maxwidth'] < $thumb_width_min) {
            $dbconf['upload_form_thumb_maxwidth'] = $thumb_width_min;
        }

        if ($dbconf['upload_form_thumb_maxwidth'] > $thumb_width_max) {
            $dbconf['upload_form_thumb_maxwidth'] = $thumb_width_max;
        }

        if ($dbconf['upload_form_thumb_maxheight'] < $thumb_height_min) {
            $dbconf['upload_form_thumb_maxheight'] = $thumb_height_min;
        }

        if ($dbconf['upload_form_thumb_maxheight'] > $thumb_height_max) {
            $dbconf['upload_form_thumb_maxheight'] = $thumb_height_max;
        }

        if ($thumb_is_square) {
            $dbconf['upload_form_thumb_maxheight'] = $dbconf['upload_form_thumb_maxwidth'];
        }

        $size = [
            is_numeric($dbconf['upload_form_thumb_maxwidth']) ? (int) $dbconf['upload_form_thumb_maxwidth'] : 0,
            is_numeric($dbconf['upload_form_thumb_maxheight']) ? (int) $dbconf['upload_form_thumb_maxheight'] : 0,
        ];

        $thumb = new DerivativeParams(
            new SizingParams(
                $size,
                (bool) $dbconf['upload_form_thumb_crop'] ? 1 : 0,
                (bool) $dbconf['upload_form_thumb_crop'] ? $size : null
            )
        );

        $types[ImageStdParams::THUMB] = $thumb;

        // slightly enlarge XSS to be bigger than thumbnail size (but smaller than XS)
        if ($dbconf['upload_form_thumb_maxwidth'] >= $types[ImageStdParams::XXSMALL]->sizing->ideal_size[0]
            or $dbconf['upload_form_thumb_maxheight'] >= $types[ImageStdParams::XXSMALL]->sizing->ideal_size[1]) {
            $xxs_maxwidth = $types[ImageStdParams::XXSMALL]->sizing->ideal_size[0];
            if ($dbconf['upload_form_thumb_maxwidth'] >= $xxs_maxwidth) {
                $xxs_maxwidth = 350;
            }

            $xxs_maxheight = $types[ImageStdParams::XXSMALL]->sizing->ideal_size[1];
            if ($dbconf['upload_form_thumb_maxheight'] >= $xxs_maxheight) {
                $xxs_maxheight = 310;
            }

            $xxs = new DerivativeParams(new SizingParams([$xxs_maxwidth, $xxs_maxheight]));

            $types[ImageStdParams::XXSMALL] = $xxs;
        }

        //
        // Piwigo 2.3 "websize" becomes "medium" size in Piwigo 2.4
        //

        // if there was no "websize resize" on Piwigo 2.3, we can't take the resize
        // settings into account, we keep the default settings of Piwigo 2.4.
        if ((bool) $dbconf['upload_form_websize_resize']) {
            $medium_width_min = 577; // default S maxwidth + 1 pixel
            $medium_width_max = 1007; // default L maxwidth - 1 pixel
            $medium_height_min = 433; // default S maxheight + 1 pixel
            $medium_height_max = 755; // default L maxheight - 1 pixel

            // width
            if (! is_numeric($dbconf['upload_form_websize_maxwidth'])) { // sometimes maxwidth="false"
                $dbconf['upload_form_websize_maxwidth'] = $medium_width_max;
            }

            if ($dbconf['upload_form_websize_maxwidth'] < $medium_width_min) {
                $dbconf['upload_form_websize_maxwidth'] = $medium_width_min;
            }

            if ($dbconf['upload_form_websize_maxwidth'] > $medium_width_max) {
                $dbconf['upload_form_websize_maxwidth'] = $medium_width_max;
            }

            // height
            if (! is_numeric($dbconf['upload_form_websize_maxheight'])) { // sometimes maxheight="false"
                $dbconf['upload_form_websize_maxheight'] = $medium_height_max;
            }

            if ($dbconf['upload_form_websize_maxheight'] < $medium_height_min) {
                $dbconf['upload_form_websize_maxheight'] = $medium_height_min;
            }

            if ($dbconf['upload_form_websize_maxheight'] > $medium_height_max) {
                $dbconf['upload_form_websize_maxheight'] = $medium_height_max;
            }

            $medium = new DerivativeParams(
                new SizingParams(
                    [
                        (int) $dbconf['upload_form_websize_maxwidth'],
                        (int) $dbconf['upload_form_websize_maxheight'],
                    ]
                )
            );

            $types[ImageStdParams::MEDIUM] = $medium;
        }

        //
        // Save derivative new settings
        //

        ImageStdParams::set_and_save($types);

        $conn->executeStatement('DELETE FROM ' . Tables::config() . ' WHERE param = :param', [
            'param' => 'disabled_derivatives',
        ]);
        new DerivativeCacheService()
            ->clearDerivativeCache();

        echo "\n" . $this->description() . "\n";
    }
}
