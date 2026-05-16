<?php

declare(strict_types=1);

namespace Piwigo\Theme;

use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Event\Lifecycle\GetPwgThemes;
use Piwigo\Template\TemplateRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class ThemeService
{
    public function __construct(
        private ThemeRepository $themeRepository,
        private EventDispatcherInterface $dispatcher,
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
        $themesEvent = new GetPwgThemes($themes);
        $this->dispatcher->dispatch($themesEvent);
        /** @var array<string,string> $result */
        $result = $themesEvent->themes;
        return $result;
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
