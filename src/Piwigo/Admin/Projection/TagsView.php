<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `tags.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\TagsPageRenderer::render()}. No `$formAction` field --
 * `F_ACTION` has zero real references in `tags.latte`'s own body (tag
 * management is entirely client-side). `$firstTags`/`$data` stay loose
 * row shapes -- each tag entry's own key set grows incrementally
 * (name/id/url_name plus more spliced on per row), not a fixed
 * structural shape worth minting its own DTO for here.
 */
#[Template('tags.latte')]
final readonly class TagsView implements View
{
    /**
     * @param list<array<string, mixed>> $firstTags
     * @param list<array<string, mixed>> $data
     */
    public function __construct(
        public string $pwgToken,
        public string $orphanTagNamesArray,
        public string $warningTags,
        public string $messageTags,
        public array $firstTags,
        public array $data,
        public int $total,
        public int $perPage,
    ) {}
}
