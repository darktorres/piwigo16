<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\ExtendForTemplatesPageRenderer::render()}.
 * `$extents` is genuinely optional -- the original code only ever
 * assigns the `extents` template key when its own `$tpl_extension` loop
 * runs at least once, omitted here (not present as an empty array) to
 * match that exact original behavior, since `extend_for_templates.tpl`
 * reads it with `{if isset($extents)}`, not `!empty()`.
 */
final readonly class ExtendForTemplatesPageContext implements TemplatePageContext
{
    /**
     * @param list<array{replacer: string, url_parameter: mixed, original_tpl: list<string>, bound_tpl: mixed, selected_tpl: mixed, selected_url: string, selected_bound: string}>|null $extents
     */
    public function __construct(
        public string $helpUrl,
        public string $adminPageTitle,
        public ?array $extents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'U_HELP' => $this->helpUrl,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];

        if ($this->extents !== null) {
            $result['extents'] = $this->extents;
        }

        return $result;
    }
}
