<?php

declare(strict_types=1);

// One-shot P23 batch 8d codemod: retargets every real call site of the
// ~55 include/functions_user.inc.php free functions (minus the handful
// handled by hand -- register_user()'s wrapper logic, pwg_login()'s event
// registration, and the 0-external-caller functions) onto their new real
// class method. See docs: user's explicit authorization for a narrowly-
// scoped Rector codemod on pure, uniform, mechanical renames at this
// scale (04-batch8-cdef-combined.md, "no scripted mass rewrites" exception).
// Discarded after this migration lands -- the diff is committed, not this
// script (project convention: one-shot backfills as throwaway scripts).

require_once __DIR__ . '/rector-rules/FuncCallToNewMethodCallRector.php';
require_once __DIR__ . '/rector-rules/QueryHashWrapperRector.php';

use Rector\Config\RectorConfig;
use Rector\Transform\Rector\FuncCall\FuncCallToStaticCallRector;
use Rector\Transform\ValueObject\FuncCallToStaticCall;
use Utils\Rector\FuncCallToNewMethodCallRector;
use Utils\Rector\QueryHashWrapperRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/..',
    ])
    ->withSkip([
        __DIR__ . '/../_data',
        __DIR__ . '/../galleries',
        // NOTE: install/db/*.php are skip-listed here for historical
        // performance/scope reasons, NOT because they're dead -- P23 batch
        // 8d pass 2a found (the hard way) that these files ARE real,
        // executable code (included by upgrade.php via UPGRADES_PATH
        // during a real upgrade run). Any real call site in install/db/
        // to a function this config's map touches must be checked and
        // fixed BY HAND after every run, same as pass 2a's
        // 94/117/122-database.php fixes -- do not assume this skip means
        // "safe to ignore".
        __DIR__ . '/../install/db',
        __DIR__ . '/../language',
        __DIR__ . '/../local',
        __DIR__ . '/../node_modules',
        __DIR__ . '/../vendor',
        // being deleted outright in this same fold -- its own function
        // definitions must not be rewritten into calls to themselves
        __DIR__ . '/../include/functions_user.inc.php',
        __DIR__ . '/../include/functions.inc.php',
    ])
    ->withRules([
        FuncCallToNewMethodCallRector::class,
        QueryHashWrapperRector::class,
    ])
    ->withConfiguredRule(FuncCallToStaticCallRector::class, [
        new FuncCallToStaticCall('check_status', 'Piwigo\Auth\AccessControl', 'checkStatus'),
        new FuncCallToStaticCall('is_a_guest', 'Piwigo\Auth\AccessControl', 'isAGuest'),
        new FuncCallToStaticCall('is_admin', 'Piwigo\Auth\AccessControl', 'isAdmin'),
        new FuncCallToStaticCall('is_webmaster', 'Piwigo\Auth\AccessControl', 'isWebmaster'),
        new FuncCallToStaticCall('is_generic', 'Piwigo\Auth\AccessControl', 'isGeneric'),
        new FuncCallToStaticCall('is_classic_user', 'Piwigo\Auth\AccessControl', 'isClassicUser'),
        new FuncCallToStaticCall('is_autorize_status', 'Piwigo\Auth\AccessControl', 'isAuthorizeStatus'),
        new FuncCallToStaticCall('can_manage_comment', 'Piwigo\Auth\AccessControl', 'canManageComment'),
        new FuncCallToStaticCall('generate_user_code', 'Piwigo\Auth\AuthService', 'generateUserCode'),
        new FuncCallToStaticCall('verify_user_code', 'Piwigo\Auth\AuthService', 'verifyUserCode'),
        // P23 batch 8d, file 2 pass 2a: functions.inc.php pure utility
        // functions -- all stateless pure renames onto new static
        // Piwigo\Core\* helper classes, same shape as the AccessControl
        // block above.
        new FuncCallToStaticCall('micro_seconds', 'Piwigo\Core\TimingHelper', 'microSeconds'),
        new FuncCallToStaticCall('get_moment', 'Piwigo\Core\TimingHelper', 'getMoment'),
        new FuncCallToStaticCall('get_elapsed_time', 'Piwigo\Core\TimingHelper', 'getElapsedTime'),
        new FuncCallToStaticCall('get_extension', 'Piwigo\Core\StringHelper', 'getExtension'),
        new FuncCallToStaticCall('get_filename_wo_extension', 'Piwigo\Core\StringHelper', 'getFilenameWoExtension'),
        new FuncCallToStaticCall('qualify_utf8', 'Piwigo\Core\StringHelper', 'qualifyUtf8'),
        new FuncCallToStaticCall('remove_accents', 'Piwigo\Core\StringHelper', 'removeAccents'),
        new FuncCallToStaticCall('pwg_transliterate', 'Piwigo\Core\StringHelper', 'pwgTransliterate'),
        new FuncCallToStaticCall('str2url', 'Piwigo\Core\StringHelper', 'str2url'),
        new FuncCallToStaticCall('get_name_from_file', 'Piwigo\Core\StringHelper', 'getNameFromFile'),
        new FuncCallToStaticCall('dateDiff', 'Piwigo\Core\DateHelper', 'dateDiff'),
        new FuncCallToStaticCall('str2DateTime', 'Piwigo\Core\DateHelper', 'str2DateTime'),
        new FuncCallToStaticCall('format_date_legacy', 'Piwigo\Core\DateHelper', 'formatDateLegacy'),
        new FuncCallToStaticCall('format_date', 'Piwigo\Core\DateHelper', 'formatDate'),
        new FuncCallToStaticCall('format_fromto', 'Piwigo\Core\DateHelper', 'formatFromto'),
        new FuncCallToStaticCall('time_since', 'Piwigo\Core\DateHelper', 'timeSince'),
        new FuncCallToStaticCall('transform_date', 'Piwigo\Core\DateHelper', 'transformDate'),
        new FuncCallToStaticCall('is_valid_mysql_datetime', 'Piwigo\Core\DateHelper', 'isValidMysqlDatetime'),
        new FuncCallToStaticCall('mkgetdir', 'Piwigo\Core\FilesystemHelper', 'mkgetdir'),
        new FuncCallToStaticCall('secure_directory', 'Piwigo\Core\FilesystemHelper', 'secureDirectory'),
        // P23 batch 8d, file 2 pass 2b-i: remaining pure/lightly-global-
        // coupled utility functions -- same static-rename shape.
        new FuncCallToStaticCall('safe_unserialize', 'Piwigo\Core\ArrayHelper', 'safeUnserialize'),
        new FuncCallToStaticCall('safe_json_decode', 'Piwigo\Core\ArrayHelper', 'safeJsonDecode'),
        new FuncCallToStaticCall('prepend_append_array_items', 'Piwigo\Core\ArrayHelper', 'prependAppendArrayItems'),
        new FuncCallToStaticCall('get_pwg_charset', 'Piwigo\Core\CharsetHelper', 'getPwgCharset'),
        new FuncCallToStaticCall('convert_charset', 'Piwigo\Core\CharsetHelper', 'convertCharset'),
        new FuncCallToStaticCall('get_device', 'Piwigo\Core\DeviceHelper', 'getDevice'),
        new FuncCallToStaticCall('mobile_theme', 'Piwigo\Core\DeviceHelper', 'mobileTheme'),
        new FuncCallToStaticCall('safe_version_compare', 'Piwigo\Core\VersionHelper', 'safeVersionCompare'),
        new FuncCallToStaticCall('get_branch_from_version', 'Piwigo\Core\VersionHelper', 'getBranchFromVersion'),
        new FuncCallToStaticCall('pwg_unique_exec_begins', 'Piwigo\Core\UniqueExecLock', 'begins'),
        new FuncCallToStaticCall('pwg_unique_exec_is_running', 'Piwigo\Core\UniqueExecLock', 'isRunning'),
        new FuncCallToStaticCall('pwg_unique_exec_ends', 'Piwigo\Core\UniqueExecLock', 'ends'),
        new FuncCallToStaticCall('get_container_info', 'Piwigo\Core\ContainerDetector', 'detect'),
        new FuncCallToStaticCall('check_lounge', 'Piwigo\Core\LoungeMaintenance', 'checkLounge'),
        new FuncCallToStaticCall('url_check_format', 'Piwigo\Validation\InputValidator', 'checkUrlFormat'),
        new FuncCallToStaticCall('email_check_format', 'Piwigo\Validation\InputValidator', 'checkEmailFormat'),
        new FuncCallToStaticCall('script_basename', 'Piwigo\Core\PageFilterHelper', 'scriptBasename'),
        new FuncCallToStaticCall('get_filter_page_value', 'Piwigo\Core\PageFilterHelper', 'getFilterPageValue'),
        // P23 batch 8d, file 2 pass 2b-ii: domain-specific utility
        // functions, still stateless/pure-rename despite living on a
        // domain class rather than a generic Core one.
        new FuncCallToStaticCall('get_languages', 'Piwigo\Lang\LangService', 'getLanguages'),
        new FuncCallToStaticCall('get_pwg_themes', 'Piwigo\Core\ThemeCatalog', 'getPwgThemes'),
        // check_theme_installed() intentionally NOT mapped (removed after
        // an initial run retargeted it everywhere): UserService::
        // checkAndSaveUserInfos()/getDefaultTheme() must keep calling it
        // bare -- tests/Integration/ExtensionLifecycleTest.php spies on
        // both via same-namespace function shadowing. Its own definition
        // stays in functions.inc.php as a permanent facade, same shape as
        // check_pwg_token() above.
        new FuncCallToStaticCall('original_to_representative', 'Piwigo\Image\ImagePathHelper', 'originalToRepresentative'),
        new FuncCallToStaticCall('original_to_format', 'Piwigo\Image\ImagePathHelper', 'originalToFormat'),
        new FuncCallToStaticCall('get_element_path', 'Piwigo\Image\ImagePathHelper', 'getElementPath'),
        new FuncCallToStaticCall('fill_caddie', 'Piwigo\Caddie\CaddieService', 'fillCurrentUserCaddie'),
        new FuncCallToStaticCall('get_privacy_level_options', 'Piwigo\Permission\PermissionService', 'getPrivacyLevelOptions'),
        new FuncCallToStaticCall('get_nb_available_comments', 'Piwigo\Comment\CommentService', 'getNbAvailableComments'),
        new FuncCallToStaticCall('get_icon', 'Piwigo\Core\RecentIconResolver', 'getIcon'),
        new FuncCallToStaticCall('pwg_debug', 'Piwigo\Core\TimingHelper', 'debug'),
    ])
    ->withPhpVersion(\Rector\ValueObject\PhpVersion::PHP_85)
    ->withParallel(timeoutSeconds: 300);
