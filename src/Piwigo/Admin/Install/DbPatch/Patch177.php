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
use Piwigo\Config\ConfigDb;
use Piwigo\Core\ArrayHelper;
use Piwigo\Image\ImageStdParams;

/**
 * Former install/db/177-database.php (P23 sub-batch 8g-3). The bare
 * safe_unserialize() call (which had ZERO runtime definitions on this
 * path until 8f's fix wave added a delegate) became
 * ArrayHelper::safeUnserialize() -- the real fix for that
 * broken-at-HEAD coupling. IMG_3XLARGE/IMG_4XLARGE became the
 * ImageStdParams::THREE_XLARGE/FOUR_XLARGE constants.
 */
final class Patch177 implements DbPatchInterface
{
    #[\Override]
    public function id(): string
    {
        return '177';
    }

    #[\Override]
    public function description(): string
    {
        return 'Add 3XL and 4XL sizes (disabled by default)';
    }

    #[\Override]
    public function apply(Connection $conn): void
    {
        ConfigDb::loadConfFromDb();

        // get default sizes from derivative_std_params
        $default_sizes = ImageStdParams::get_default_sizes();

        // set new derivatives to add
        $new_derivatives = [ImageStdParams::THREE_XLARGE, ImageStdParams::FOUR_XLARGE];

        // get current disabled derivative_std_params
        $disabled_derivatives = ArrayHelper::safeUnserialize(ImageStdParams::get_disabled_type_map());

        // get the new derivative_std_params and merge with current disabled derivative_std_params
        $new_disabled_derivatives = array_intersect_key($default_sizes, array_flip($new_derivatives));
        $disabled = array_merge($disabled_derivatives, $new_disabled_derivatives);

        // set and set new derivative_std_params in disabled list
        ImageStdParams::set_and_save_disabled($disabled);

        echo "\n" . $this->description() . "\n";
    }
}
