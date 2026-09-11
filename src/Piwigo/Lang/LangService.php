<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Db\TypedRepository;

/**
 * Thin object-oriented facade over Lang for constructor injection --
 * t()/l10n() delegate straight to Lang::t(), matching the now-deleted free
 * function l10n()'s former contract exactly (both accept a possibly-null
 * key for parity with legacy call sites that pass an unchecked array
 * value).
 *
 * `loadLanguageForPlugin()`/`isInstalledLocale()` (a plugin `.po` loader)
 * were removed (P29.6): zero real callers ever wired them up, and the
 * real, generalized mechanism ended up being
 * `PluginConfig\PluginRegistry::onLoadingLang()`, which reuses `Lang::
 * load()`'s own existing current-locale/parent-locale/default-locale
 * fallback cascade rather than the narrower explicit-locale-only shape
 * this class's own version had.
 */
final readonly class LangService
{
    public function __construct(
        private Lang $lang,
    ) {}

    public function t(?string $key, mixed ...$args): string
    {
        return $this->lang->t($key ?? '', ...$args);
    }

    public function l10n(?string $key, mixed ...$args): string
    {
        return $this->t($key, ...$args);
    }

    /**
     * returns an array with a list of {language_code => language_name} for
     * every language installed under the core language/ tree
     *
     * Static since it needs no other instance state, matching
     * InputValidator's own mixed static/instance precedent; takes Paths
     * as an explicit parameter since a static method can't reach
     * constructor-injected state.
     *
     * @return array<string, string>
     */
    public static function getLanguages(Paths $paths, EntityManagerInterface $entityManager): array
    {
        $repo = TypedRepository::narrow($entityManager->getRepository(LanguageEntity::class), LangRepository::class);

        $languages = [];
        foreach ($repo->findAllRows() as $row) {
            if (is_dir($paths->root . 'language/' . $row->id)) {
                $languages[$row->id] = $row->name;
            }
        }

        return $languages;
    }
}
