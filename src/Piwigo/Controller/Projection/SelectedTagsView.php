<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `include/selected_tags.inc.latte`'s own typed view -- rendered by
 * {@see \Piwigo\Controller\GalleryController::__invoke()} before it
 * constructs its own `IndexView`, whose `$selectedTagsTemplate` carries
 * this render's `Html` result.
 */
#[Template('include/selected_tags.inc.latte')]
final readonly class SelectedTagsView implements View, HasPageAssets
{
    /**
     * @param list<SelectedTagRow> $selectRelatedTags empty whenever the
     *   section carries no selected tag; the template renders nothing then.
     */
    public function __construct(
        public array $selectRelatedTags,
    ) {}

    /**
     * `include/selected_tags.inc.latte`'s own unconditional
     * `{do combineCss(...)}` (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/css/components/selected_tags.css', id: 'selected_tags'),
        ];
    }
}
