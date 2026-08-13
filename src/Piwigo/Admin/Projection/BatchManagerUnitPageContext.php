<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\BatchManagerUnitPageRenderer::render()}. `$navbar`
 * and `$elementIds` are genuinely optional -- both are only ever
 * assigned inside the renderer's own `count($cat_elements_id) > 0`
 * branch, omitted here (not present as a null value) to match that
 * exact original behavior. `$storageCategory` is also optional and
 * genuinely dead: it's overwritten once per matching category across
 * the whole per-image loop (last write wins), and `batch_manager_unit.latte`
 * never actually reads `$STORAGE_CATEGORY` -- kept here anyway as a
 * faithful 1:1 port of the original assign() call,
 * not removed as an out-of-scope dead-code cleanup. `$elements` is
 * always included (even empty) since `batch_manager_unit.latte` reads it
 * with `{if !empty($elements)}`, not `isset()`.
 */
final readonly class BatchManagerUnitPageContext implements TemplatePageContext
{
    /**
     * @param array<array-key, string> $levelOptions
     * @param list<string> $activePlugins
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int}|null $navbar
     * @param array<array-key, string> $cacheKeys
     * @param list<array<string, mixed>> $elements
     */
    public function __construct(
        public string $uElementsPage,
        public array $levelOptions,
        public string $adminPageTitle,
        public string $pwgToken,
        public array $activePlugins,
        public int $perPage,
        public ?array $navbar,
        public ?string $storageCategory,
        public ?string $elementIds,
        public array $cacheKeys,
        public array $elements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'U_ELEMENTS_PAGE' => $this->uElementsPage,
            'level_options' => $this->levelOptions,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'CSRF_TOKEN' => $this->pwgToken,
            'ACTIVE_PLUGINS' => $this->activePlugins,
            'per_page' => $this->perPage,
            'elements' => $this->elements,
        ];

        if ($this->navbar !== null) {
            $result['navbar'] = $this->navbar;
        }

        if ($this->storageCategory !== null) {
            $result['STORAGE_CATEGORY'] = $this->storageCategory;
        }

        if ($this->elementIds !== null) {
            $result['ELEMENT_IDS'] = $this->elementIds;
        }

        $result['CACHE_KEYS'] = $this->cacheKeys;

        return $result;
    }
}
