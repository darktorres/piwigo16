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
        __DIR__ . '/index.php',
        __DIR__ . '/themes/default',
    ])
    ->withSkip([
        __DIR__ . '/install/db', __DIR__ . '/language',
        __DIR__ . '/include/feedcreator.class.php',
        __DIR__ . '/include/phpqrcode.php',
        __DIR__ . '/include/passwordhash.class.php',
        __DIR__ . '/themes', __DIR__ . '/vendor',
        // load_external_filters() uses implode('', $callback) for compile_id — array callbacks only.
        // Skip ArrayToFirstClassCallableRector on Template so set_prefilter callbacks stay as arrays.
        \Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector::class => [
            __DIR__ . '/src/Piwigo/Template/Template.php',
        ],
        // version_compare() first-class callable has a multi-overload type PHPStan rejects for usort.
        // The explicit closure fn(string $a, string $b): int => version_compare($a, $b) is correct.
        \Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [
            __DIR__ . '/src/Piwigo/Calendar/CalendarBase.php',
        ],
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
        'tabsheet'              => \Piwigo\Admin\Tabsheet::class,
        'languages'             => \Piwigo\Admin\Languages::class,
        'updates'               => \Piwigo\Admin\Updates::class,
        'plugins'               => \Piwigo\Admin\Plugins::class,
        'DummyPlugin_maintain'  => \Piwigo\Admin\DummyPluginMaintain::class,
        'themes'                => \Piwigo\Admin\Themes::class,
        'DummyTheme_maintain'   => \Piwigo\Admin\DummyThemeMaintain::class,
        'c13y_internal'         => \Piwigo\Admin\Integrity\C13yInternal::class,
        'check_integrity'       => \Piwigo\Admin\Integrity\CheckIntegrity::class,
        // Admin image backends
        'imageInterface'        => \Piwigo\Admin\Image\ImageInterface::class,
        'pwg_image'             => \Piwigo\Admin\Image\PwgImage::class,
        'image_imagick'         => \Piwigo\Admin\Image\ImageImagick::class,
        'image_ext_imagick'     => \Piwigo\Admin\Image\ImageExtImagick::class,
        'image_gd'              => \Piwigo\Admin\Image\ImageGd::class,
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
