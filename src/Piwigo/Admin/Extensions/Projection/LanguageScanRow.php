<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions\Projection;

/**
 * One entry of {@see \Piwigo\Admin\Extensions\ExtensionScanner::scanLanguages()}'s
 * own fixed per-language `common.po` scan result. `$extension` is always
 * `null` -- bundled languages aren't independently-versioned PEM packages
 * the way plugins/themes are (see `scanLanguages()`'s own docblock) --
 * kept here (rather than omitted) purely so `Admin\Extensions\
 * ExtensionUpdateChecker`'s cross-type callers can read `->extension` on
 * the `PluginScanRow|ThemeScanRow|LanguageScanRow` union without an
 * `instanceof` check.
 */
final readonly class LanguageScanRow
{
    public function __construct(
        public string $name,
        public string $code,
        public string $version,
        public string $uri,
        public string $author,
        public ?string $extension = null,
    ) {}
}
