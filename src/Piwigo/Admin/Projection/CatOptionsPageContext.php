<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Category\Projection\CategorySelectOptions;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\CatOptionsPageRenderer::render()}.
 * `$categoryOptionTrue`/`$categoryOptionFalse` are
 * {@see \Piwigo\Category\CategoryService}'s own
 * `category_option_true`/`category_option_false` (+ `_selected`) pairs --
 * always set, one of this renderer's own 4 branches runs unconditionally.
 */
final readonly class CatOptionsPageContext implements TemplatePageContext
{
    public function __construct(
        public string $helpUrl,
        public string $formAction,
        public string $section,
        public string $catOptionsTrueLabel,
        public string $catOptionsFalseLabel,
        public string $pwgToken,
        public string $adminPageTitle,
        public CategorySelectOptions $categoryOptionTrue,
        public CategorySelectOptions $categoryOptionFalse,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'U_HELP' => $this->helpUrl,
            'F_ACTION' => $this->formAction,
            'L_SECTION' => $this->section,
            'L_CAT_OPTIONS_TRUE' => $this->catOptionsTrueLabel,
            'L_CAT_OPTIONS_FALSE' => $this->catOptionsFalseLabel,
            'CSRF_TOKEN' => $this->pwgToken,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'category_option_true' => $this->categoryOptionTrue->options,
            'category_option_true_selected' => $this->categoryOptionTrue->selected,
            'category_option_false' => $this->categoryOptionFalse->options,
            'category_option_false_selected' => $this->categoryOptionFalse->selected,
        ];
    }
}
