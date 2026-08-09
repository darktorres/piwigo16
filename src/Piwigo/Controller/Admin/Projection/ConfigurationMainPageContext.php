<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by the `'main'` case of
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * own render-time `switch`. `$main`'s own checkbox values (formerly a
 * separate `Template::append($tpl_var, ..., merge: true)` loop run right
 * after this context was constructed) are merged into the plain PHP
 * `$main` array before construction instead, so this is the one and
 * only write to the `main` template key.
 */
final readonly class ConfigurationMainPageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $main
     * @param array<int, string> $groupOptions
     */
    public function __construct(
        public ?bool $orderByIsCustom,
        public array $main,
        public array $groupOptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'main' => $this->main,
            'group_options' => $this->groupOptions,
        ];

        if ($this->orderByIsCustom !== null) {
            $result['ORDER_BY_IS_CUSTOM'] = $this->orderByIsCustom;
        }

        return $result;
    }
}
