<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::
 * processSizes()}'s own return value -- threaded back into `handle()`'s
 * own render-time `switch` to build the final {@see ConfigurationSizesView}.
 * `$saveSuccess` (the form-submitted-successfully branch) and
 * `$derivatives`/`$ferrors`/`$resizeQuality`/`$sizes` (the
 * validation-failure branch, which also flips `handle()`'s own
 * `$sizesLoadedInTpl` guard so its own fresh-derivatives computation is
 * skipped, leaving only the POSTed-back values here) are mutually
 * exclusive. `$sizes` is independently nullable from the other 3
 * (matching the original's own independent per-field `isset($post[...])`
 * loop) even though in practice `$original_fields` always includes the 3
 * hidden-input fields `configuration_sizes.latte` reads unguarded.
 */
final readonly class ConfigurationSizesResult
{
    /**
     * @param array<string, DerivativeSizeRow>|null $derivatives
     */
    public function __construct(
        public ?string $saveSuccess,
        public ?array $derivatives,
        public ?SizesFormErrors $ferrors,
        public ?string $resizeQuality,
        public ?ConfigurationSizesTabData $sizes,
    ) {}
}
