<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\DeclareStrictTypesRector;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/include', __DIR__ . '/admin',
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
    ->withRules([DeclareStrictTypesRector::class])
    ->withImportNames(importShortClasses: false, removeUnusedImports: false)
    ->withParallel();
