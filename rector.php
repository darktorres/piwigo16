<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/include', __DIR__ . '/admin',
        __DIR__ . '/install.php', __DIR__ . '/upgrade.php',
        __DIR__ . '/index.php', __DIR__ . '/ws.php', __DIR__ . '/picture.php',
        __DIR__ . '/identification.php', __DIR__ . '/profile.php',
        __DIR__ . '/register.php', __DIR__ . '/password.php',
    ])
    ->withSkip([
        __DIR__ . '/install/db', __DIR__ . '/language',
        __DIR__ . '/include/smarty', __DIR__ . '/include/feedcreator.class.php',
        __DIR__ . '/include/minify',
        __DIR__ . '/include/phpmailer',
        __DIR__ . '/include/phpqrcode.php',
        __DIR__ . '/include/emogrifier.class.php',
        __DIR__ . '/themes', __DIR__ . '/vendor',
    ])
    ->withPhpSets(php85: true)
    ->withImportNames(importShortClasses: false, removeUnusedImports: false)
    ->withParallel();
