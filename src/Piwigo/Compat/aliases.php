<?php

declare(strict_types=1);

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
        'PluginMaintain'       => \Piwigo\Admin\PluginMaintain::class,
        'ThemeMaintain'        => \Piwigo\Admin\ThemeMaintain::class,
        'DummyPlugin_maintain' => \Piwigo\Admin\DummyPlugin_maintain::class,
        'DummyTheme_maintain'  => \Piwigo\Admin\DummyTheme_maintain::class,
        // Template
        'Template'             => \Piwigo\Template\Template::class,
        'ScriptLoader'         => \Piwigo\Template\ScriptLoader::class,
        'CssLoader'            => \Piwigo\Template\CssLoader::class,
        'Combinable'           => \Piwigo\Template\Combinable::class,
        'FileCombiner'         => \Piwigo\Template\FileCombiner::class,
        'PwgTemplateAdapter'   => \Piwigo\Template\PwgTemplateAdapter::class,
        // WS layer
        'PwgError'             => \Piwigo\Ws\PwgError::class,
        'PwgNamedArray'        => \Piwigo\Ws\PwgNamedArray::class,
        'PwgNamedStruct'       => \Piwigo\Ws\PwgNamedStruct::class,
        'PwgRequestHandler'    => \Piwigo\Ws\PwgRequestHandler::class,
        'PwgResponseEncoder'   => \Piwigo\Ws\Encoder\PwgResponseEncoder::class,
        'PwgServer'            => \Piwigo\Ws\PwgServer::class,
        // Calendar
        'CalendarBase'         => \Piwigo\Calendar\CalendarBase::class,
        'CalendarMonthly'      => \Piwigo\Calendar\CalendarMonthly::class,
        'CalendarWeekly'       => \Piwigo\Calendar\CalendarWeekly::class,
        // Image params
        'DerivativeImage'      => \Piwigo\Image\DerivativeImage::class,
        'SrcImage'             => \Piwigo\Image\SrcImage::class,
        'ImageStdParams'       => \Piwigo\Image\ImageStdParams::class,
        'DerivativeParams'     => \Piwigo\Image\DerivativeParams::class,
        'WatermarkParams'      => \Piwigo\Image\WatermarkParams::class,
        'SizingParams'         => \Piwigo\Image\SizingParams::class,
        'ImageRect'            => \Piwigo\Image\ImageRect::class,
        // Menu / blocks
        'BlockManager'         => \Piwigo\Menu\BlockManager::class,
        'RegisteredBlock'      => \Piwigo\Menu\RegisteredBlock::class,
        'DisplayBlock'         => \Piwigo\Menu\DisplayBlock::class,
        // Cache
        'PersistentCache'      => \Piwigo\Cache\PersistentCache::class,
        'PersistentFileCache'  => \Piwigo\Cache\PersistentFileCache::class,
        // Admin classes
        'check_integrity'      => \Piwigo\Admin\Integrity\check_integrity::class,
        'c13y_internal'        => \Piwigo\Admin\Integrity\c13y_internal::class,
        'pwg_image'            => \Piwigo\Admin\Image\pwg_image::class,
        'imageInterface'       => \Piwigo\Admin\Image\imageInterface::class,
        'image_imagick'        => \Piwigo\Admin\Image\image_imagick::class,
        'image_ext_imagick'    => \Piwigo\Admin\Image\image_ext_imagick::class,
        'image_gd'             => \Piwigo\Admin\Image\image_gd::class,
        'tabsheet'             => \Piwigo\Admin\tabsheet::class,
        'languages'            => \Piwigo\Admin\languages::class,
        'updates'              => \Piwigo\Admin\updates::class,
        'plugins'              => \Piwigo\Admin\plugins::class,
        'themes'               => \Piwigo\Admin\themes::class,
        // Session
        'PwgSession'           => \Piwigo\Session\PwgSession::class,
        // Auth
        'PwgBase32'            => \Piwigo\Auth\PwgBase32::class,
        'PwgTOTP'              => \Piwigo\Auth\PwgTOTP::class,
        // Core
        'Logger'               => \Piwigo\Core\Logger::class,
    ];
    if (isset($map[$class])) {
        class_alias($map[$class], $class);
    }
}, prepend: false);
