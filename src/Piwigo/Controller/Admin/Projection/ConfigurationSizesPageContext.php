<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::
 * processSizes()} -- see {@see ConfigurationPageContext} for
 * `handle()`'s own assigns. `$saveSuccess` (the form-submitted-
 * successfully branch) and `$derivatives`/`$ferrors`/`$resizeQuality`
 * (the validation-failure branch, which also flips `handle()`'s own
 * `$this->sizesLoadedInTpl` guard) are mutually exclusive.
 */
final readonly class ConfigurationSizesPageContext implements TemplatePageContext
{
    /**
     * @param array<string, array<string, mixed>>|null $derivatives
     * @param array<string, mixed>|null $ferrors
     */
    public function __construct(
        public ?string $saveSuccess,
        public ?array $derivatives,
        public ?array $ferrors,
        public mixed $resizeQuality,
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

        if ($this->derivatives !== null) {
            $result['derivatives'] = $this->derivatives;
            $result['ferrors'] = $this->ferrors;
            $result['resize_quality'] = $this->resizeQuality;
        }

        return $result;
    }
}
