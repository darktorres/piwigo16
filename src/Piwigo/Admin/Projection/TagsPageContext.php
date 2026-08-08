<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\TagsPageRenderer::render()}. `$firstTags`/`$data`
 * stay loose row shapes -- each tag entry's own key set grows
 * incrementally (name/id/url_name plus more spliced on per row), not a
 * fixed structural shape worth minting its own DTO for here.
 */
final readonly class TagsPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $firstTags
     * @param list<array<string, mixed>> $data
     */
    public function __construct(
        public string $formAction,
        public string $pwgToken,
        public string $orphanTagNamesArray,
        public string $warningTags,
        public string $messageTags,
        public array $firstTags,
        public array $data,
        public int $total,
        public int $perPage,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'F_ACTION' => $this->formAction,
            'PWG_TOKEN' => $this->pwgToken,
            'orphan_tag_names_array' => $this->orphanTagNamesArray,
            'warning_tags' => $this->warningTags,
            'message_tags' => $this->messageTags,
            'first_tags' => $this->firstTags,
            'data' => $this->data,
            'total' => $this->total,
            'per_page' => $this->perPage,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];
    }
}
