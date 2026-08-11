<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\PatternFilter;

return static function (Configuration $config): Configuration {
    // Platform requirements (php, ext-*) aren't "imported" the way a composer
    // package with an autoload map is, so this tool's symbol-usage analysis
    // can't evaluate them meaningfully.
    $config->addPatternFilter(PatternFilter::fromString('/^(php|ext-.+)$/'));

    return $config;
};
