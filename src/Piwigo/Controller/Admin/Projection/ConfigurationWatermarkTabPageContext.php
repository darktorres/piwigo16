<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by the `'watermark'` case of
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * own render-time `switch` -- distinct from {@see
 * ConfigurationWatermarkPageContext}, which is `processWatermark()`'s
 * own POST handler context. `$watermark` is genuinely optional -- the
 * original code only assigns it when `Template::getTemplateVars(
 * 'watermark') === null` (i.e. `processWatermark()` hasn't already set
 * it on a validation failure earlier in this same request).
 */
final readonly class ConfigurationWatermarkTabPageContext implements TemplatePageContext
{
    /**
     * @param array<string, string> $watermarkFiles
     * @param array<string, mixed>|null $watermark
     */
    public function __construct(
        public array $watermarkFiles,
        public ?array $watermark,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'watermark_files' => $this->watermarkFiles,
        ];

        if ($this->watermark !== null) {
            $result['watermark'] = $this->watermark;
        }

        return $result;
    }
}
