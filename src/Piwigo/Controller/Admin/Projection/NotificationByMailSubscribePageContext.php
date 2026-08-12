<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\NotificationByMailSubController::handle()}'s
 * own `case 'subscribe':` display branch. Must be assigned before that
 * same branch's own `Template::assignVarFromHandle('DOUBLE_SELECT',
 * 'double_select')` call, which parses `double_select.latte` using only
 * the vars assigned up to that point.
 */
final readonly class NotificationByMailSubscribePageContext implements TemplatePageContext
{
    /**
     * @param array<string, string> $categoryOptionTrue
     * @param list<string> $categoryOptionTrueSelected
     * @param array<string, string> $categoryOptionFalse
     * @param list<string> $categoryOptionFalseSelected
     */
    public function __construct(
        public string $lCatOptionsTrue,
        public string $lCatOptionsFalse,
        public array $categoryOptionTrue,
        public array $categoryOptionTrueSelected,
        public array $categoryOptionFalse,
        public array $categoryOptionFalseSelected,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'subscribe' => true,
            'L_CAT_OPTIONS_TRUE' => $this->lCatOptionsTrue,
            'L_CAT_OPTIONS_FALSE' => $this->lCatOptionsFalse,
            'category_option_true' => $this->categoryOptionTrue,
            'category_option_true_selected' => $this->categoryOptionTrueSelected,
            'category_option_false' => $this->categoryOptionFalse,
            'category_option_false_selected' => $this->categoryOptionFalseSelected,
        ];
    }
}
