<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__,
    ])
    ->withSkip([
        // Generated PHPStan Latte analysis files (bin/piwigo
        // phpstan-latte:compile) -- Latte's own compiled-output style,
        // regenerated wholesale; not code to modernize.
        __DIR__ . '/_analysis',
        // Generated analysis shims (bin/piwigo phpstan-latte:generate-shims)
        // -- auto-generated signatures, regenerated wholesale; a prior
        // Rector run hand-"fixed" this file directly (adding a `: never`
        // return type that's locally true for the throw-only stub body
        // but wrong for what the shim represents), which cascaded into
        // ~135 false deadCode.unreachable errors across nearly every
        // .latte template. Regenerate via `composer generate:latte-shims`
        // instead of letting Rector touch it.
        __DIR__ . '/tools/phpstan/Latte/Generated',
        __DIR__ . '/_data',
        __DIR__ . '/galleries',
        __DIR__ . '/install/db',
        __DIR__ . '/language',
        __DIR__ . '/local',
        __DIR__ . '/node_modules',
        // PHPStan stub files -- carefully-crafted minimal signatures for
        // third-party classes, used only for static analysis; not real
        // runtime code to modernize.
        __DIR__ . '/phpstan-stubs',
        // Gitignored runtime plugin-drop-in mount (only index.php is
        // tracked) -- any real plugin code placed here in a local checkout
        // isn't part of this project's own codebase.
        __DIR__ . '/plugins',
        // public/ symlinks back to already-scanned themes/, dist/,
        // _data/combined/ (Part II web-root isolation) -- without these,
        // the exact same files get scanned (and modernized) twice under
        // two different path strings.
        __DIR__ . '/public/_data/combined',
        __DIR__ . '/public/dist',
        __DIR__ . '/public/themes',
        // Gitignored runtime user-uploaded content, never source code.
        __DIR__ . '/upload',
        __DIR__ . '/vendor',
    ])
    ->withPhpSets(php85: true)
    ->withPreparedSets(typeDeclarations: true, instanceOf: true)
    ->withImportNames()
    ->withParallel(timeoutSeconds: 300);
