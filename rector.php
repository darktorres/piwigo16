<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/include', __DIR__ . '/admin', __DIR__ . '/install', __DIR__ . '/src',
        __DIR__ . '/install.php', __DIR__ . '/upgrade.php',
        __DIR__ . '/index.php', __DIR__ . '/ws.php', __DIR__ . '/picture.php',
        __DIR__ . '/identification.php', __DIR__ . '/profile.php',
        __DIR__ . '/register.php', __DIR__ . '/password.php',
        __DIR__ . '/themes/default',
    ])
    ->withSkip([
        __DIR__ . '/install/db', __DIR__ . '/language',
        __DIR__ . '/include/smarty', __DIR__ . '/include/feedcreator.class.php',
        __DIR__ . '/include/minify',
        __DIR__ . '/include/phpmailer',
        __DIR__ . '/include/phpqrcode.php',
        __DIR__ . '/include/emogrifier.class.php',
        __DIR__ . '/include/jshrink.class.php',
        __DIR__ . '/include/passwordhash.class.php',
        __DIR__ . '/include/mdetect.php',
        __DIR__ . '/admin/include/pclzip.lib.php',
        __DIR__ . '/themes', __DIR__ . '/vendor',
    ])
    ->withPhpSets(php85: true)
    ->withSets([SetList::TYPE_DECLARATION])
    ->withRules([
        DeclareStrictTypesRector::class,
        RemoveUselessParamTagRector::class,
        RemoveUselessReturnTagRector::class,
        RemoveUselessVarTagRector::class,
    ])
    ->withConfiguredRule(RenameClassRector::class, [
        // Cache
        'PersistentCache'       => \Piwigo\Cache\PersistentCache::class,
        'PersistentFileCache'   => \Piwigo\Cache\PersistentFileCache::class,
        // Core
        'Logger'                => \Piwigo\Core\Logger::class,
        // Auth
        'PwgBase32'             => \Piwigo\Auth\PwgBase32::class,
        'PwgTOTP'               => \Piwigo\Auth\PwgTOTP::class,
        // Menu
        'BlockManager'          => \Piwigo\Menu\BlockManager::class,
        'RegisteredBlock'       => \Piwigo\Menu\RegisteredBlock::class,
        'DisplayBlock'          => \Piwigo\Menu\DisplayBlock::class,
        // Session
        'PwgSession'            => \Piwigo\Session\PwgSession::class,
        // Calendar
        'CalendarBase'          => \Piwigo\Calendar\CalendarBase::class,
        'CalendarMonthly'       => \Piwigo\Calendar\CalendarMonthly::class,
        'CalendarWeekly'        => \Piwigo\Calendar\CalendarWeekly::class,
        // Search
        'QSearchScope'          => \Piwigo\Search\QSearchScope::class,
        'QNumericRangeScope'    => \Piwigo\Search\QNumericRangeScope::class,
        'QDateRangeScope'       => \Piwigo\Search\QDateRangeScope::class,
        'QSingleToken'          => \Piwigo\Search\QSingleToken::class,
        'QMultiToken'           => \Piwigo\Search\QMultiToken::class,
        'QExpression'           => \Piwigo\Search\QExpression::class,
        'QResults'              => \Piwigo\Search\QResults::class,
        // Image / Derivatives
        'WatermarkParams'       => \Piwigo\Image\WatermarkParams::class,
        'ImageStdParams'        => \Piwigo\Image\ImageStdParams::class,
        'ImageRect'             => \Piwigo\Image\ImageRect::class,
        'SizingParams'          => \Piwigo\Image\SizingParams::class,
        'DerivativeParams'      => \Piwigo\Image\DerivativeParams::class,
        'SrcImage'              => \Piwigo\Image\SrcImage::class,
        'DerivativeImage'       => \Piwigo\Image\DerivativeImage::class,
        // WS core
        'PwgError'              => \Piwigo\Ws\PwgError::class,
        'PwgNamedArray'         => \Piwigo\Ws\PwgNamedArray::class,
        'PwgNamedStruct'        => \Piwigo\Ws\PwgNamedStruct::class,
        'PwgRequestHandler'     => \Piwigo\Ws\PwgRequestHandler::class,
        'PwgResponseEncoder'    => \Piwigo\Ws\Encoder\PwgResponseEncoder::class,
        'PwgServer'             => \Piwigo\Ws\PwgServer::class,
        // WS protocols
        'PwgRestRequestHandler' => \Piwigo\Ws\Protocol\PwgRestRequestHandler::class,
        'PwgXmlWriter'          => \Piwigo\Ws\Protocol\PwgXmlWriter::class,
        'PwgRestEncoder'        => \Piwigo\Ws\Protocol\PwgRestEncoder::class,
        'PwgJsonEncoder'        => \Piwigo\Ws\Protocol\PwgJsonEncoder::class,
        'PwgXmlRpcEncoder'      => \Piwigo\Ws\Protocol\PwgXmlRpcEncoder::class,
        'PwgSerialPhpEncoder'   => \Piwigo\Ws\Protocol\PwgSerialPhpEncoder::class,
        // Admin base classes
        'PluginMaintain'        => \Piwigo\Admin\PluginMaintain::class,
        'ThemeMaintain'         => \Piwigo\Admin\ThemeMaintain::class,
        // Admin classes
        'tabsheet'              => \Piwigo\Admin\tabsheet::class,
        'languages'             => \Piwigo\Admin\languages::class,
        'updates'               => \Piwigo\Admin\updates::class,
        'plugins'               => \Piwigo\Admin\plugins::class,
        'DummyPlugin_maintain'  => \Piwigo\Admin\DummyPlugin_maintain::class,
        'themes'                => \Piwigo\Admin\themes::class,
        'DummyTheme_maintain'   => \Piwigo\Admin\DummyTheme_maintain::class,
        'c13y_internal'         => \Piwigo\Admin\Integrity\c13y_internal::class,
        'check_integrity'       => \Piwigo\Admin\Integrity\check_integrity::class,
        // Admin image backends
        'imageInterface'        => \Piwigo\Admin\Image\imageInterface::class,
        'pwg_image'             => \Piwigo\Admin\Image\pwg_image::class,
        'image_imagick'         => \Piwigo\Admin\Image\image_imagick::class,
        'image_ext_imagick'     => \Piwigo\Admin\Image\image_ext_imagick::class,
        'image_gd'              => \Piwigo\Admin\Image\image_gd::class,
        // Template cluster
        'Combinable'            => \Piwigo\Template\Combinable::class,
        'Script'                => \Piwigo\Template\Script::class,
        'Css'                   => \Piwigo\Template\Css::class,
        'CssLoader'             => \Piwigo\Template\CssLoader::class,
        'ScriptLoader'          => \Piwigo\Template\ScriptLoader::class,
        'FileCombiner'          => \Piwigo\Template\FileCombiner::class,
        'PwgTemplateAdapter'    => \Piwigo\Template\PwgTemplateAdapter::class,
        'Template'              => \Piwigo\Template\Template::class,
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: false)
    ->withParallel();
