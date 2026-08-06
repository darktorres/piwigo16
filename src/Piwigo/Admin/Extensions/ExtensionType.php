<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Piwigo\Admin\PluginLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\Paths;
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
     * This type's PEM category id (pemPluginsCategory()/pemThemesCategory()/
     * pemLanguagesCategory()) -- a closed 3-case match onto the 3 real
     * typed getters, replacing a former dynamic-key lookup through the
     * generic bag (Config generic-accessor removal, design #6).
     */
    public function pemCategoryId(CurrentConfig $currentConfig): int
    {
        return match ($this) {
            self::Plugin => $currentConfig->pemPluginsCategory(),
            self::Theme => $currentConfig->pemThemesCategory(),
            self::Language => $currentConfig->pemLanguagesCategory(),
        };
    }

    /**
     * The plural form used by the WS API's own `pwg.extensions.ignoreUpdate`
     * wire parameter (and, historically, the retired `updates_ignored`
     * config blob) -- plural, unlike $this->value (singular). Null for an
     * unrecognized string, so callers can reject an invalid `type` param
     * the same way they already reject any other malformed input.
     */
    public static function fromPluralWsParam(string $value): ?self
    {
        return match ($value) {
            'plugins' => self::Plugin,
            'themes' => self::Theme,
            'languages' => self::Language,
            default => null,
        };
    }

    public function scanDirectory(Paths $paths, CurrentConfig $currentConfig): string
    {
        return match ($this) {
            self::Plugin => PluginLoader::pluginsPath($paths),
            self::Theme => $currentConfig->themesPath(),
            self::Language => $paths->root . 'language/',
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
