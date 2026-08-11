<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\GalleryController::__invoke()} up through its
 * `assignVarFromHandle('SELECTED_TAGS_TEMPLATE', 'selected_tags')`
 * call -- the `selected_tags.inc.tpl` sub-template it parses there reads
 * `SELECT_RELATED_TAGS`, so that field (and everything assigned before
 * it) must reach the real template before that parse happens. See
 * {@see GalleryThumbnailsPageContext} for everything __invoke() assigns
 * after that point. `$uModeCreated`/`$uModePosted` are shared by 2
 * mutually exclusive branches (the "no chronology filter active" branch
 * and the "toggle to the other chronology field" branch) that both only
 * ever target these same 2 template keys, never both at once.
 * `$searchInSetButton`/`$searchInSetAction`/`$searchInSetUrl` are this
 * controller's "viewing one category" variant -- see
 * {@see GalleryThumbnailsPageContext} for its "viewing a tag" variant of
 * the same 3 keys (mutually exclusive in practice, same last-write-wins
 * behavior as the original preserved by each being its own independent
 * optional trio).
 */
final readonly class GalleryPageContext implements TemplatePageContext
{
    /**
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int} $thumbNavbar
     * @param array<array-key, mixed>|null $selectRelatedTags
     */
    public function __construct(
        public array $thumbNavbar,
        public string $uCanonical,
        public bool $useStandardPages,
        public string $title,
        public int $nbItems,
        public ?string $uModeNormal,
        public ?string $uModeFlat,
        public ?string $uModeCreated,
        public ?string $uModePosted,
        public ?bool $searchInSetButton,
        public ?string $searchInSetAction,
        public ?string $searchInSetUrl,
        public ?array $selectRelatedTags,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'thumb_navbar' => $this->thumbNavbar,
            'U_CANONICAL' => $this->uCanonical,
            'use_standard_pages' => $this->useStandardPages,
            'TITLE' => $this->title,
            'NB_ITEMS' => $this->nbItems,
        ];

        if ($this->uModeNormal !== null) {
            $result['U_MODE_NORMAL'] = $this->uModeNormal;
        }

        if ($this->uModeFlat !== null) {
            $result['U_MODE_FLAT'] = $this->uModeFlat;
        }

        if ($this->uModeCreated !== null) {
            $result['U_MODE_CREATED'] = $this->uModeCreated;
        }

        if ($this->uModePosted !== null) {
            $result['U_MODE_POSTED'] = $this->uModePosted;
        }

        if ($this->searchInSetButton !== null) {
            $result['SEARCH_IN_SET_BUTTON'] = $this->searchInSetButton;
            $result['SEARCH_IN_SET_ACTION'] = $this->searchInSetAction;
            $result['SEARCH_IN_SET_URL'] = $this->searchInSetUrl;
        }

        if ($this->selectRelatedTags !== null) {
            $result['SELECT_RELATED_TAGS'] = $this->selectRelatedTags;
        }

        return $result;
    }
}
