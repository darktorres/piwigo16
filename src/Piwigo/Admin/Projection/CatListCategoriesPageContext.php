<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\CatListPageRenderer::render()} once its own
 * categories-display loop finishes -- `cat_list.tpl`'s own
 * `{if count($categories)}` requires the key to always exist, so
 * `$categories` is a plain required field, always included (even
 * empty), not the optional-omission idiom. `$parentEditUrl` is
 * genuinely optional: the original code only assigns the `PARENT_EDIT`
 * key when a parent category is selected -- omitted here (not present
 * as a null value) to match that exact original behavior.
 */
final readonly class CatListCategoriesPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $categories
     */
    public function __construct(
        public ?string $parentEditUrl,
        public array $categories,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'categories' => $this->categories,
        ];
        if ($this->parentEditUrl !== null) {
            $result['PARENT_EDIT'] = $this->parentEditUrl;
        }

        return $result;
    }
}
