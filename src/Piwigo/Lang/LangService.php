<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Db\TypedRepository;

/**
 * Thin object-oriented facade over Lang/Translator for constructor
 * injection -- t()/l10n() delegate straight to Lang::t(), matching the
 * now-deleted free function l10n()'s former contract exactly (both accept
 * a possibly-null key for parity with legacy call sites that pass an
 * unchecked array value).
 *
 * loadLanguageForPlugin() is new: discovers and loads a plugin's own PO
 * file (`<pluginDir>/language/<locale>/plugin.po`). `$locale` is an
 * explicit parameter rather than resolved internally from
 * `Piwigo\Users\CurrentUser` -- `Piwigo\Lang\` is L1Infrastructure, and
 * deptrac's ruleset only lets L1Infrastructure depend on L0Data, not
 * upward on `Users`' L2aCoreDomain (same layer shape as `Kernel`'s
 * own `CurrentUser::attachGlobals()` exclusion). Callers resolve the
 * active locale themselves (typically `CurrentUser::get()->language`) and
 * pass it in.
 *
 * [SEC-26] `$locale` in real deployments is ultimately user/DB-controlled
 * (a registered user's stored language preference); validated against the
 * real, filesystem-verifiable set of installed `language/<locale>/`
 * directories under the core `language/` tree before it's composed into
 * any path, blocking path traversal (`../../etc/passwd`) or reads of
 * arbitrary files outside a plugin's language directory. There is no
 * `CurrentConfig::availableLanguages()` accessor to validate against
 * (not in the 277-key SCHEMA) -- the filesystem check is both simpler and
 * authoritative (a "locale" with no matching core directory isn't a real,
 * loadable locale regardless of what a DB row claims).
 */
final readonly class LangService
{
    public function __construct(
        private Lang $lang,
        private Paths $paths,
        private Translator $translator,
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
     * as an explicit parameter rather than $this->paths since a static
     * method can't reach constructor-injected state, same as
     * loadLanguageForPlugin()'s own $locale parameter.
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

    public function loadLanguageForPlugin(string $pluginDir, string $locale): bool
    {
        if (! $this->isInstalledLocale($locale)) {
            return false;
        }

        $poFile = rtrim($pluginDir, '/') . '/language/' . $locale . '/plugin.po';
        if (! is_readable($poFile)) {
            return false;
        }

        $this->translator->load($locale, $poFile);

        return true;
    }

    private function isInstalledLocale(string $locale): bool
    {
        if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale) !== 1) {
            return false;
        }

        return is_dir($this->paths->root . 'language/' . $locale);
    }
}
