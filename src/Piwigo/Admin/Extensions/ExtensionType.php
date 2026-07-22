<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Piwigo\Config\Config;
use Piwigo\Core\ActivitySystem;
use Piwigo\Db\Tables;

/**
 * The 3 kinds of installable extension, replacing the plugins/themes/
 * languages god-classes' near-identical-but-not-quite-identical PEM sync,
 * filesystem scan, and lifecycle logic (~90% duplicated across the 3
 * legacy classes, confirmed by direct read of all three). Real per-type
 * differences (config key, table, scan directory, marker file, activity
 * logging) are exposed here as accessors so PemCatalog/ExtensionScanner/
 * ExtensionLifecycle can stay single generic classes instead of 3 parallel
 * ones.
 */
enum ExtensionType: string
{
    case Plugin = 'plugin';
    case Theme = 'theme';
    case Language = 'language';

    public function table(): string
    {
        return match ($this) {
            self::Plugin => Tables::plugins(),
            self::Theme => Tables::themes(),
            self::Language => Tables::languages(),
        };
    }

    /**
     * The $conf key holding this type's PEM category id
     * (pem_plugins_category / pem_themes_category / pem_languages_category).
     */
    public function configCategoryKey(): string
    {
        return match ($this) {
            self::Plugin => 'pem_plugins_category',
            self::Theme => 'pem_themes_category',
            self::Language => 'pem_languages_category',
        };
    }

    /**
     * This type's key in Config::updatesIgnored()'s
     * array{plugins: list<string>, themes: list<string>, languages: list<string>}
     * shape -- plural, unlike $this->value (singular). Real bug found via
     * PHPStan: ExtensionUpdateChecker::checkExtensions() used to index that
     * array with $type->value directly, silently missing every read/write
     * (the ignore-list feature never actually excluded anything, and every
     * run persisted stray singular-keyed junk alongside the real data).
     */
    public function updatesIgnoredKey(): string
    {
        return match ($this) {
            self::Plugin => 'plugins',
            self::Theme => 'themes',
            self::Language => 'languages',
        };
    }

    public function scanDirectory(): string
    {
        return match ($this) {
            self::Plugin => \Piwigo\Admin\PluginLoader::pluginsPath(),
            self::Theme => Config::themesPath(),
            self::Language => \Piwigo\Core\CurrentPaths::get()->root . 'language/',
        };
    }

    /**
     * The file whose presence in a scanned directory marks it as a real
     * extension of this type (also the file searched for by basename
     * inside a downloaded archive to locate the extension's root).
     */
    public function markerFilename(): string
    {
        return match ($this) {
            self::Plugin => 'main.inc.php',
            self::Theme => 'themeconf.inc.php',
            // Real, verified bug found via live admin-page verification, not
            // assumed: languages.class.php (and its own extract_language_files())
            // both still check for 'common.lang.php', but this rewrite's
            // language/ tree already migrated every locale to gettext .po
            // files (confirmed: zero common.lang.php files exist anywhere
            // under language/, every locale has a common.po instead, with
            // "Source: common.lang.php" left in its header comment as the
            // conversion's own paper trail) -- the legacy marker check has
            // been silently matching nothing since that migration, which is
            // why languages_installed.php/languages_new.php render an empty
            // list against a live fixture -- languages.class.php itself is
            // untouched (bug predates this phase; not otherwise exercised
            // end-to-end before this batch's live verification).
            self::Language => 'common.po',
        };
    }

    /**
     * Extension ids bundled with Piwigo core -- excluded from
     * "incompatible extension" checks. Mirrors updates.class.php's own
     * $default_plugins/$default_themes/$default_languages properties
     * (languages.class.php has no bundled-language concept, so this is
     * empty for Language).
     *
     * @return list<string>
     */
    public function defaultIds(): array
    {
        return match ($this) {
            self::Plugin => ['LocalFilesEditor', 'language_switch', 'TakeATour', 'AdminTools'],
            self::Theme => ['modus', 'elegant', 'smartpocket'],
            self::Language => [],
        };
    }

    /**
     * Only Plugin/Theme lifecycle actions are logged via pwg_activity() in
     * the legacy classes (languages.class.php::perform_action() never
     * calls it) -- null here preserves that exact asymmetry rather than
     * inventing new logging for languages.
     */
    public function activityType(): ?int
    {
        return match ($this) {
            self::Plugin => ActivitySystem::Plugin,
            self::Theme => ActivitySystem::Theme,
            self::Language => null,
        };
    }
}
