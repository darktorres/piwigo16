<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Category\Projection\CategorySelectOptions;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\GroupPermPageRenderer::render()}.
 * `$categoryOptionTrue`/`$categoryOptionFalse` are
 * {@see \Piwigo\Category\CategoryService}'s own
 * `category_option_true`/`category_option_false` (+ `_selected`) pairs.
 */
final readonly class GroupPermPageContext implements TemplatePageContext
{
    public function __construct(
        public string $title,
        public string $catOptionsTrueLabel,
        public string $catOptionsFalseLabel,
        public string $formAction,
        public string $pwgToken,
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
            'TITLE' => $this->title,
            'L_CAT_OPTIONS_TRUE' => $this->catOptionsTrueLabel,
            'L_CAT_OPTIONS_FALSE' => $this->catOptionsFalseLabel,
            'F_ACTION' => $this->formAction,
            'CSRF_TOKEN' => $this->pwgToken,
            'category_option_true' => $this->categoryOptionTrue->options,
            'category_option_true_selected' => $this->categoryOptionTrue->selected,
            'category_option_false' => $this->categoryOptionFalse->options,
            'category_option_false_selected' => $this->categoryOptionFalse->selected,
        ];
    }
}
