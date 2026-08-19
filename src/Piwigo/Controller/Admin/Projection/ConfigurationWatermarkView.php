<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `configuration_watermark.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * `'watermark'` case, merged with `processWatermark()`'s own POST-handler
 * result (see that method's own return type) and the whole-page shared
 * fields every `configuration_*.latte` tab needs -- see {@see
 * ConfigurationMainView}. `$watermark` is always present by render time
 * (either `processWatermark()`'s own validation-failure echo, or a fresh
 * default computed here) -- the template's own body reads its inner keys
 * (`$watermark['file']`/`['position']`/etc.) unguarded. `$ferrors` is
 * only ever non-null on `processWatermark()`'s own validation-failure
 * branch.
 */
#[Template('configuration_watermark.latte')]
final readonly class ConfigurationWatermarkView implements View
{
    /**
     * @param array<string, string> $watermarkFiles
     * @param array<string, mixed> $watermark
     * @param array<string, mixed>|null $ferrors
     */
    public function __construct(
        public array $watermarkFiles,
        public array $watermark,
        public ?array $ferrors,
        public string $fAction,
        public ?string $saveSuccess,
        public int $isWebmaster,
        public string $csrfToken,
    ) {}
}
