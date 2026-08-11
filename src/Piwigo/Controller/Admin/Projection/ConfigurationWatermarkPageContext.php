<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::
 * processWatermark()} -- see {@see ConfigurationPageContext} for
 * `handle()`'s own assigns. `$saveSuccess` (the form-submitted-
 * successfully branch) and `$watermark`/`$ferrors` (the
 * validation-failure branch, which also makes `handle()`'s own
 * `$template->getTemplateVars('watermark') === null` guard false)
 * are mutually exclusive.
 */
final readonly class ConfigurationWatermarkPageContext implements TemplatePageContext
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

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [];

        if ($this->saveSuccess !== null) {
            $result['save_success'] = $this->saveSuccess;
        }

        if ($this->watermark !== null) {
            $result['watermark'] = $this->watermark;
            $result['ferrors'] = $this->ferrors;
        }

        return $result;
    }
}
