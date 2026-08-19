<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `include/selected_tags.inc.latte`'s own typed view -- rendered by
 * {@see \Piwigo\Controller\GalleryController::__invoke()} before it
 * constructs its own `IndexView`, whose `$selectedTagsTemplate` carries
 * this render's `Html` result.
 */
#[Template('include/selected_tags.inc.latte')]
final readonly class SelectedTagsView implements View
{
    /**
     * @param array<array-key, mixed>|null $selectRelatedTags
     */
    public function __construct(
        public ?array $selectRelatedTags,
    ) {}
}
