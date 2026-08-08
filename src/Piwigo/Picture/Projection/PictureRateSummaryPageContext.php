<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'rate_summary' template variable assigned by
 * {@see \Piwigo\Picture\PictureRateRenderer::render()}. Stays a loose
 * row shape: `score` is read straight from PictureController's own
 * growing per-request display bag, the same "not a single reusable
 * domain shape" accumulator this file's own render() docblock already
 * documents for `$picture`.
 */
final readonly class PictureRateSummaryPageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $rateSummary
     */
    public function __construct(
        public array $rateSummary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'rate_summary' => $this->rateSummary,
        ];
    }
}
