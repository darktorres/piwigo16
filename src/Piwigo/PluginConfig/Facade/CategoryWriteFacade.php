<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Facade;

use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\CategoryId;

/**
 * Narrow, purpose-built write facade handed out by `ExtensionContext::
 * categoriesWrite()` -- same discipline as `ImageWriteFacade`'s own
 * docblock: never `CategoryService`/raw SQL directly, and deliberately
 * ungated (see that class's own docblock for why).
 *
 * Grounded in `../piwigo16-plugins/AdminTools_16.3.0/include/
 * events.inc.php`'s own `admintools_save_category()`: `single_update()`
 * on `CATEGORIES_TABLE` (name/comment).
 */
final readonly class CategoryWriteFacade
{
    public function __construct(
        private CategoryService $categoryService,
    ) {}

    public function updateNameAndComment(int $categoryId, string $name, ?string $comment = null): void
    {
        $data = ['name' => $name];
        if ($comment !== null) {
            $data['comment'] = $comment;
        }
        $this->categoryService->updateFields(CategoryId::from($categoryId), $data);
    }
}
