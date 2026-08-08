<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable assigned by
 * {@see \Piwigo\Controller\Admin\SiteUpdateSubController::handle()} when
 * the previous sync run left images with no md5sum computed.
 */
final readonly class SiteUpdateSaveErrorPageContext implements TemplatePageContext
{
    public function __construct(
        public string $saveError,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'save_error' => $this->saveError,
        ];
    }
}
