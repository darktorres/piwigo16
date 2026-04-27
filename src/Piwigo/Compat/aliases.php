<?php

declare(strict_types=1);

use Piwigo\Admin\DummyPlugin_maintain;
use Piwigo\Admin\DummyTheme_maintain;
use Piwigo\Admin\Image\image_ext_imagick;
use Piwigo\Admin\Image\image_gd;
use Piwigo\Admin\Image\image_imagick;
use Piwigo\Admin\Image\imageInterface;
use Piwigo\Admin\Image\pwg_image;
use Piwigo\Admin\Integrity\c13y_internal;
use Piwigo\Admin\Integrity\check_integrity;
use Piwigo\Admin\languages;
use Piwigo\Admin\PluginMaintain;
use Piwigo\Admin\plugins;
use Piwigo\Admin\tabsheet;
use Piwigo\Admin\ThemeMaintain;
use Piwigo\Admin\themes;
use Piwigo\Admin\updates;
use Piwigo\Auth\PwgBase32;
use Piwigo\Auth\PwgTOTP;
use Piwigo\Cache\PersistentCache;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Calendar\CalendarBase;
use Piwigo\Calendar\CalendarMonthly;
use Piwigo\Calendar\CalendarWeekly;
use Piwigo\Core\Logger;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\ImageRect;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SizingParams;
use Piwigo\Image\SrcImage;
use Piwigo\Image\WatermarkParams;
use Piwigo\Menu\BlockManager;
use Piwigo\Menu\DisplayBlock;
use Piwigo\Menu\RegisteredBlock;
use Piwigo\Session\PwgSession;
use Piwigo\Template\Combinable;
use Piwigo\Template\CssLoader;
use Piwigo\Template\FileCombiner;
use Piwigo\Template\PwgTemplateAdapter;
use Piwigo\Template\ScriptLoader;
use Piwigo\Template\Template;
use Piwigo\Ws\Encoder\PwgResponseEncoder;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgRequestHandler;
use Piwigo\Ws\PwgServer;

/**
 * Backwards-compatibility class_alias shims for plugin/theme code that
 * references pre-PSR-4 short class names. Loaded via composer.json autoload.files.
 *
 * Uses a lazy spl_autoload_register so the Piwigo\ classes are not eagerly
 * loaded at vendor/autoload.php time — they are loaded on first use.
 *
 * EOL policy: each alias is removed two minor versions after Phase 3 ships.
 * Plugins/themes must update class references to the Piwigo\ FQN before then.
 */
spl_autoload_register(static function (string $class): void {
    static $map = [
        // Plugin / theme maintenance contracts
        'PluginMaintain'       => PluginMaintain::class,
        'ThemeMaintain'        => ThemeMaintain::class,
        'DummyPlugin_maintain' => DummyPlugin_maintain::class,
        'DummyTheme_maintain'  => DummyTheme_maintain::class,
        // Template
        'Template'             => Template::class,
        'ScriptLoader'         => ScriptLoader::class,
        'CssLoader'            => CssLoader::class,
        'Combinable'           => Combinable::class,
        'FileCombiner'         => FileCombiner::class,
        'PwgTemplateAdapter'   => PwgTemplateAdapter::class,
        // WS layer
        'PwgError'             => PwgError::class,
        'PwgNamedArray'        => PwgNamedArray::class,
        'PwgNamedStruct'       => PwgNamedStruct::class,
        'PwgRequestHandler'    => PwgRequestHandler::class,
        'PwgResponseEncoder'   => PwgResponseEncoder::class,
        'PwgServer'            => PwgServer::class,
        // Calendar
        'CalendarBase'         => CalendarBase::class,
        'CalendarMonthly'      => CalendarMonthly::class,
        'CalendarWeekly'       => CalendarWeekly::class,
        // Image params
        'DerivativeImage'      => DerivativeImage::class,
        'SrcImage'             => SrcImage::class,
        'ImageStdParams'       => ImageStdParams::class,
        'DerivativeParams'     => DerivativeParams::class,
        'WatermarkParams'      => WatermarkParams::class,
        'SizingParams'         => SizingParams::class,
        'ImageRect'            => ImageRect::class,
        // Menu / blocks
        'BlockManager'         => BlockManager::class,
        'RegisteredBlock'      => RegisteredBlock::class,
        'DisplayBlock'         => DisplayBlock::class,
        // Cache
        'PersistentCache'      => PersistentCache::class,
        'PersistentFileCache'  => PersistentFileCache::class,
        // Admin classes
        'check_integrity'      => check_integrity::class,
        'c13y_internal'        => c13y_internal::class,
        'pwg_image'            => pwg_image::class,
        'imageInterface'       => imageInterface::class,
        'image_imagick'        => image_imagick::class,
        'image_ext_imagick'    => image_ext_imagick::class,
        'image_gd'             => image_gd::class,
        'tabsheet'             => tabsheet::class,
        'languages'            => languages::class,
        'updates'              => updates::class,
        'plugins'              => plugins::class,
        'themes'               => themes::class,
        // Session
        'PwgSession'           => PwgSession::class,
        // Auth
        'PwgBase32'            => PwgBase32::class,
        'PwgTOTP'              => PwgTOTP::class,
        // Core
        'Logger'               => Logger::class,
    ];
    if (isset($map[$class])) {
        class_alias($map[$class], $class);
    }
}, prepend: false);
