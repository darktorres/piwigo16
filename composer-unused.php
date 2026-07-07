<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;
use ComposerUnused\ComposerUnused\Configuration\PatternFilter;

return static function (Configuration $config): Configuration {
    // Platform requirements (php, ext-*) aren't "imported" the way a composer
    // package with an autoload map is, so this tool's symbol-usage analysis
    // can't evaluate them meaningfully. docs/PLAN-REPLAY.md P0 step 13: this
    // tool's real work starts once actual vendor packages land in P5.
    $config->addPatternFilter(PatternFilter::fromString('/^(php|ext-.+)$/'));

    // composer-unused discovers consumed symbols only by walking composer.json's
    // *declared autoload paths* (AutoloadType::all() — psr-4/psr-0/classmap/files).
    // There's no non-dev `autoload` entry yet (nothing is namespaced until P6), so
    // include/env.inc.php — a plain procedural file, genuinely using
    // Symfony\Component\Dotenv\Dotenv — is structurally invisible to it. Verified
    // setAdditionalFilesFor() doesn't help: that feeds a package's *provided*-symbol
    // discovery, not consumed-symbol scanning, so it's a no-op for this case.
    $config->addNamedFilter(NamedFilter::fromString('symfony/dotenv'));

    return $config;
};
