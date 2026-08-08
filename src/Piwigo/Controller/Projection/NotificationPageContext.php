<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\NotificationController}.
 */
final readonly class NotificationPageContext implements TemplatePageContext
{
    public function __construct(
        public string $feedUrl,
        public string $feedImageOnlyUrl,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'U_FEED' => $this->feedUrl,
            'U_FEED_IMAGE_ONLY' => $this->feedImageOnlyUrl,
        ];
    }
}
