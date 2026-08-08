<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by the `'main'` case of
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * own render-time `switch`. Constructed immediately at that case's
 * original position (not deferred to {@see ConfigurationPageContext}'s
 * end-of-method batch): `handle()` immediately follows with a
 * `Template::append()` loop that adds each checkbox's value into the
 * `main` key this context just assigned -- `append()` is a different
 * mechanism, out of scope for conversion, but it only works correctly
 * if `main` already has a real value in the live template by the time
 * it runs.
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
