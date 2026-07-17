<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 batch 8f-4/8f-5/8f-6: compatibility surface for the FROZEN one-shot
// historical scripts the upgrade machinery includes at runtime
// (install/db/*.php via UpgradeService::getAvailableUpgradeIds()'s scan +
// UpgradeFeedRunner::run()/UpgradeRunner::performUpgrade()'s include
// loops, and install/upgrade_X.Y.Z.php via performUpgrade()'s
// $current_release include) -- those frozen files are excluded from
// migration by standing decision ("keep them out for now, we might not
// even keep those files") and still call the bare function names /
// constants below at include time. These thin delegates keep those frozen
// calls working, exactly like include/dblayer/functions_mysqli.inc.php's
// deliberately-kept facades for the same files' bare pwg_query() family
// (P23 batch 8f-2). They are NOT a general-purpose API: every non-frozen
// caller in the codebase targets Piwigo\Config\ConfigDb::* /
// Piwigo\Admin\Install\UpgradeService::* directly, and these delegates
// disappear whenever the frozen install/db family does.
//
// The upgrade machinery itself (the former 9 free functions of this file)
// moved to Piwigo\Admin\Install\UpgradeService in P23 sub-batch 8f-6
// (prepare_conf_upgrade()'s define block was inlined into the
// upgrade_feed.php entry shell -- SEC-60 forbids define() in src/Piwigo).
//
// function_exists()/defined() guards for safety against double-include on
// mixed entry paths (this file is include_once'd from upgrade.php and
// upgrade_feed.php).

if (! function_exists('load_conf_from_db')) {
    /**
     * FROZEN-SCRIPT DELEGATE (25 install/db//install/upgrade_*.php callers).
     *
     * @param string $condition SQL condition
     */
    function load_conf_from_db($condition = '', bool $die_on_condition_with_no_result = true): void
    {
        \Piwigo\Config\ConfigDb::loadConfFromDb($condition, $die_on_condition_with_no_result);
    }
}
if (! function_exists('conf_update_param')) {
    /**
     * FROZEN-SCRIPT DELEGATE (25 install/db//install/upgrade_*.php callers).
     *
     * @param string $param
     * @param mixed $value
     * @param bool $updateGlobal
     * @param ?callable $parser
     */
    function conf_update_param($param, $value, $updateGlobal = false, $parser = null): void
    {
        \Piwigo\Config\ConfigDb::confUpdateParam($param, $value, $updateGlobal, $parser);
    }
}
if (! function_exists('safe_unserialize')) {
    /**
     * FROZEN-SCRIPT DELEGATE (install/db/177-database.php,
     * install/db/181-database.php). Every non-frozen caller targets
     * Piwigo\Core\ArrayHelper::safeUnserialize() directly.
     *
     * @param array<int|string, mixed>|string $value
     * @return mixed the unserialized value, false if $value is a malformed
     *   serialized string, or $value itself unchanged if it isn't a string
     */
    function safe_unserialize($value)
    {
        return \Piwigo\Core\ArrayHelper::safeUnserialize($value);
    }
}
if (! function_exists('clear_derivative_cache')) {
    /**
     * FROZEN-SCRIPT DELEGATE (install/db/119-database.php,
     * install/db/123-database.php). Every non-frozen caller targets
     * Piwigo\Image\DerivativeCacheService::clearDerivativeCache() directly.
     *
     * @param 'all'|string|array<int|string, string> $types
     */
    function clear_derivative_cache($types = 'all'): void
    {
        new \Piwigo\Image\DerivativeCacheService()->clearDerivativeCache($types);
    }
}
if (! function_exists('invalidate_user_cache')) {
    /**
     * FROZEN-SCRIPT DELEGATE (install/db/69-database.php,
     * install/db/135-database.php, install/db/136-database.php). Every
     * non-frozen caller targets
     * Piwigo\Cache\UserCacheInvalidator::invalidate() directly.
     */
    function invalidate_user_cache(bool $full = true): void
    {
        \Piwigo\Cache\UserCacheInvalidator::invalidate($full);
    }
}
if (! function_exists('get_available_upgrade_ids')) {
    /**
     * FROZEN-SCRIPT DELEGATE (17 install/upgrade_X.Y.Z.php callers use the
     * bare name to compute which install/db/*.php tasks still need to run).
     * Every non-frozen caller targets UpgradeService::getAvailableUpgradeIds()
     * directly (P23 sub-batch 8f-6).
     *
     * @return array<int, string>
     */
    function get_available_upgrade_ids(): array
    {
        return \Piwigo\Admin\Install\UpgradeService::getAvailableUpgradeIds();
    }
}

// P23 sub-batch 8f-5: same frozen-script compatibility surface as the
// delegates above, for the IMG_* constants of the deleted
// include/derivative_std_params.inc.php (thin aliases over
// Piwigo\Image\ImageStdParams's class constants, the canonical values
// since P23 batch 8c). Every real caller reads the class constants
// directly; only the FROZEN install/db/123-database.php (IMG_THUMB/
// IMG_XXSMALL/IMG_MEDIUM) and install/db/177-database.php (IMG_3XLARGE/
// IMG_4XLARGE) still read the bare names, and both only ever run through
// the upgrade machinery that include_once's this exact file first. All 12
// are defined (not just the 5 read today) so a frozen script never
// silently diverges from the historical alias set. defined() guards for
// safety against double-include on mixed entry paths, mirroring the
// function_exists() guards above.
defined('IMG_SQUARE') or define('IMG_SQUARE', \Piwigo\Image\ImageStdParams::SQUARE);
defined('IMG_THUMB') or define('IMG_THUMB', \Piwigo\Image\ImageStdParams::THUMB);
defined('IMG_XXSMALL') or define('IMG_XXSMALL', \Piwigo\Image\ImageStdParams::XXSMALL);
defined('IMG_XSMALL') or define('IMG_XSMALL', \Piwigo\Image\ImageStdParams::XSMALL);
defined('IMG_SMALL') or define('IMG_SMALL', \Piwigo\Image\ImageStdParams::SMALL);
defined('IMG_MEDIUM') or define('IMG_MEDIUM', \Piwigo\Image\ImageStdParams::MEDIUM);
defined('IMG_LARGE') or define('IMG_LARGE', \Piwigo\Image\ImageStdParams::LARGE);
defined('IMG_XLARGE') or define('IMG_XLARGE', \Piwigo\Image\ImageStdParams::XLARGE);
defined('IMG_XXLARGE') or define('IMG_XXLARGE', \Piwigo\Image\ImageStdParams::XXLARGE);
defined('IMG_3XLARGE') or define('IMG_3XLARGE', \Piwigo\Image\ImageStdParams::THREE_XLARGE);
defined('IMG_4XLARGE') or define('IMG_4XLARGE', \Piwigo\Image\ImageStdParams::FOUR_XLARGE);
defined('IMG_CUSTOM') or define('IMG_CUSTOM', \Piwigo\Image\ImageStdParams::CUSTOM);
