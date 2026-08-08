<?php

declare(strict_types=1);

namespace Piwigo\Section\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'favorite' template variable assigned by
 * {@see \Piwigo\Section\SectionPopulator::populate()}'s own
 * `Section::Favorites` branch, only when the current user has at least
 * one visible favorite.
 */
final readonly class SectionFavoritePageContext implements TemplatePageContext
{
    public function __construct(
        public string $removeAllUrl,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'favorite' => [
                'U_FAVORITE' => $this->removeAllUrl,
            ],
        ];
    }
}
