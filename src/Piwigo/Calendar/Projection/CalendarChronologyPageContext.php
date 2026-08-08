<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Calendar\CalendarRenderer::render()}'s own
 * category-calling branch.
 */
final readonly class CalendarChronologyPageContext implements TemplatePageContext
{
    public function __construct(
        public string $fileChronologyView,
        public string $chronologyTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'FILE_CHRONOLOGY_VIEW' => $this->fileChronologyView,
            'chronology' => [
                'TITLE' => $this->chronologyTitle,
            ],
        ];
    }
}
