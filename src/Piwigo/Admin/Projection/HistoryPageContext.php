<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\HistoryPageRenderer::render()}.
 */
final readonly class HistoryPageContext implements TemplatePageContext
{
    /**
     * @param array<string, string> $displayThumbnails
     */
    public function __construct(
        public string $fAction,
        public string $apiMethod,
        public int $userId,
        public ?string $userName,
        public string $imageId,
        public string $ip,
        public string $start,
        public string $end,
        public array $displayThumbnails,
        public string $displayThumbnailSelected,
        public int $guestId,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'F_ACTION' => $this->fAction,
            'API_METHOD' => $this->apiMethod,
            'USER_ID' => $this->userId,
            'USER_NAME' => $this->userName,
            'IMAGE_ID' => $this->imageId,
            'IP' => $this->ip,
            'START' => $this->start,
            'END' => $this->end,
            'display_thumbnails' => $this->displayThumbnails,
            'display_thumbnail_selected' => $this->displayThumbnailSelected,
            'guest_id' => $this->guestId,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];
    }
}
