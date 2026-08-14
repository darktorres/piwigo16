<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Facade;

use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Core\Projection\ThemeListing;
use Piwigo\Core\ThemeRepository;

/**
 * Narrow, purpose-built read facade handed out by `ExtensionContext::
 * themes()` -- same discipline as `ImageReadFacade`'s own docblock: never
 * `ThemeCatalog`/raw SQL directly.
 *
 * Grounded in `../piwigo16-plugins/AdminTools_16.3.0/include/
 * MultiView.class.php`'s own real `ws_get_data()` query, which lists
 * every installed theme's id for its custom `multiView.getData` WS
 * method (P27.14), alongside the users/languages it already covers.
 */
final readonly class ThemeReadFacade
{
    public function __construct(
        private ThemeRepository $themeRepository,
    ) {}

    /**
     * @return list<BasicThemeInfo>
     */
    public function listBasic(): array
    {
        return array_map(
            static fn (ThemeListing $theme): BasicThemeInfo => new BasicThemeInfo(ThemeId::from($theme->id), $theme->name),
            $this->themeRepository->findAllIdsAndNames(),
        );
    }
}
