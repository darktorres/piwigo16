<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\CatListPageRenderer::render()} right before its
 * own categories-display loop. `categories` is seeded empty here -- the
 * real rows are appended on top afterward via
 * `Template::append('categories', ...)`, a separate call this context
 * deliberately doesn't touch. `$parentEditUrl` is genuinely optional:
 * the original code only assigns the `PARENT_EDIT` key when a parent
 * category is selected -- omitted here (not present as a null value)
 * to match that exact original behavior.
 */
final readonly class CatListCategoriesPageContext implements TemplatePageContext
{
    public function __construct(
        public ?string $parentEditUrl,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'categories' => [],
        ];
        if ($this->parentEditUrl !== null) {
            $result['PARENT_EDIT'] = $this->parentEditUrl;
        }

        return $result;
    }
}
