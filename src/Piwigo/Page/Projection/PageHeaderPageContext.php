<?php

declare(strict_types=1);

namespace Piwigo\Page\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Page\PageHeaderRenderer::render()}.
 *
 * All 13 keys are always present. `$headerNotes`, `$metaRef` and
 * `$pageRefresh` used to be omitted when null, to match the key-presence
 * behaviour of the pre-conversion Smarty -- but a missing key was only
 * ever Smarty's way of spelling "no value", and it costs the templates
 * their ability to ask the question directly: an omitted key leaves the
 * variable *undefined*, so `{if $page_refresh !== null}` would read an
 * undefined variable and the template had to reach for `empty()` or
 * `isset()` instead (P58-B2). Emitting the key with its null makes the
 * nullable property the single source of truth, and `Template::
 * getTemplateVars()` resolves a missing key through `?? null` anyway, so
 * its two readers in `HtmlService` cannot tell the difference. This
 * class is assigned exactly once per request, by the single caller
 * above, so there is no later context that an always-present key could
 * overwrite.
 */
final readonly class PageHeaderPageContext implements TemplatePageContext
{
    /**
     * @param list<string> $bodyClasses
     * @param list<string>|null $headerNotes
     * @param array{TIME: string, U_REFRESH: string}|null $pageRefresh
     * @param list<Html> $headElements
     */
    public function __construct(
        public string $galleryTitle,
        public Html $pageBanner,
        public string $bodyId,
        public string $contentEncoding,
        public string $pageTitle,
        public string $homeUrl,
        public string $levelSeparator,
        public bool $showMobileAppBanner,
        public array $bodyClasses,
        public ?array $headerNotes,
        public ?int $metaRef,
        public ?array $pageRefresh,
        public array $headElements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'GALLERY_TITLE' => $this->galleryTitle,
            'PAGE_BANNER' => $this->pageBanner,
            'BODY_ID' => $this->bodyId,
            'CONTENT_ENCODING' => $this->contentEncoding,
            'PAGE_TITLE' => $this->pageTitle,
            'U_HOME' => $this->homeUrl,
            'LEVEL_SEPARATOR' => $this->levelSeparator,
            'SHOW_MOBILE_APP_BANNER' => $this->showMobileAppBanner,
            'BODY_CLASSES' => $this->bodyClasses,
            'head_elements' => $this->headElements,
            'header_notes' => $this->headerNotes,
            'meta_ref' => $this->metaRef,
            'page_refresh' => $this->pageRefresh,
        ];
    }
}
