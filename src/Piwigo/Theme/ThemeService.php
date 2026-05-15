<?php

declare(strict_types=1);

namespace Piwigo\Theme;

use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;

final readonly class ThemeService
{
    public function __construct(
        private ThemeRepository $themeRepository,
    ) {
    }

    /** @return array<string,string> */
    public function getActiveThemes(bool $showMobile = false): array
    {
        $themes = [];
        $rows   = $this->themeRepository->findAll();
        foreach ($rows as $row) {
            if ($row['id'] === Config::mobilTheme()) {
                if (!$showMobile) {
                    continue;
                }
                $row['name'] = (is_string($row['name'] ?? null) ? $row['name'] : '') . (' (' . Lang::t('Mobile') . ')');
            }
            $themeId = is_string($row['id'] ?? null) ? $row['id'] : '';
            if ($this->isInstalled($themeId)) {
                $themes[$themeId] = is_string($row['name'] ?? null) ? $row['name'] : '';
            }
        }
        return EventDispatcher::dispatch('get_pwg_themes', $themes);
    }

    public function isInstalled(string $themeId): bool
    {
        return file_exists(Config::themesDir() . '/' . $themeId . '/themeconf.inc.php');
    }

    public function getThemeconf(string $key): mixed
    {
        return TemplateRegistry::current()->getThemeconf($key);
    }
}
