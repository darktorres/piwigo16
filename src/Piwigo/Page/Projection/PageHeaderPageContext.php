<?php

declare(strict_types=1);

namespace Piwigo\Page\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Page\PageHeaderRenderer::render()}. `$headerNotes`,
 * `$metaRef`, and `$pageRefresh` are genuinely optional -- the original
 * code only ever assigns those 3 template keys under their own runtime
 * condition, omitted here (not present as a null value) to match that
 * exact original behavior.
 */
final readonly class PageHeaderPageContext implements TemplatePageContext
{
    /**
     * @param list<string> $bodyClasses
     * @param list<string>|null $headerNotes
     * @param array{TIME: string, U_REFRESH: string}|null $pageRefresh
     */
    public function __construct(
        public string $galleryTitle,
        public string $pageBanner,
        public string $bodyId,
        public string $contentEncoding,
        public string $pageTitle,
        public string $homeUrl,
        public string $levelSeparator,
        public bool $showMobileAppBanner,
        public array $bodyClasses,
        public string $bodyData,
        public ?array $headerNotes,
        public ?int $metaRef,
        public ?array $pageRefresh,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'GALLERY_TITLE' => $this->galleryTitle,
            'PAGE_BANNER' => $this->pageBanner,
            'BODY_ID' => $this->bodyId,
            'CONTENT_ENCODING' => $this->contentEncoding,
            'PAGE_TITLE' => $this->pageTitle,
            'U_HOME' => $this->homeUrl,
            'LEVEL_SEPARATOR' => $this->levelSeparator,
            'SHOW_MOBILE_APP_BANNER' => $this->showMobileAppBanner,
            'BODY_CLASSES' => $this->bodyClasses,
            'BODY_DATA' => $this->bodyData,
        ];

        if ($this->headerNotes !== null) {
            $result['header_notes'] = $this->headerNotes;
        }

        if ($this->metaRef !== null) {
            $result['meta_ref'] = $this->metaRef;
        }

        if ($this->pageRefresh !== null) {
            $result['page_refresh'] = $this->pageRefresh;
        }

        return $result;
    }
}
