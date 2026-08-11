<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by the `'comments'` case of
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * own render-time `switch`. `$comments`'s own checkbox values are merged
 * into the plain PHP `$comments` array before construction, same as
 * {@see ConfigurationMainPageContext}.
 */
final readonly class ConfigurationCommentsPageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $comments
     */
    public function __construct(
        public array $comments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'comments' => $this->comments,
        ];
    }
}
