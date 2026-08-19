<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::
 * processWatermark()}'s own return value -- threaded back into `handle()`'s
 * own render-time `switch` to build the final {@see
 * ConfigurationWatermarkView}. `$saveSuccess` (the form-submitted-
 * successfully branch) and `$watermark`/`$ferrors` (the
 * validation-failure branch, which also flips `handle()`'s own
 * `$watermarkLoadedInTpl` guard) are mutually exclusive.
 */
final readonly class ConfigurationWatermarkResult
{
    /**
     * @param array<string, string>|null $watermark
     * @param array<string, mixed>|null $ferrors
     */
    public function __construct(
        public ?string $saveSuccess,
        public ?array $watermark,
        public ?array $ferrors,
    ) {}
}
