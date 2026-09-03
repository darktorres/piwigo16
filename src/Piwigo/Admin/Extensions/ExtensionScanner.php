<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Extensions\Projection\LanguageScanRow;
use Piwigo\Admin\Extensions\Projection\PluginScanRow;
use Piwigo\Admin\Extensions\Projection\ThemeScanRow;
use Piwigo\Admin\PluginLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CharsetHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserStatus;

/**
 * Filesystem scan for installable extensions, replacing get_fs_plugins()/
 * get_fs_themes()/get_fs_languages() (plugins.class.php/themes.class.php/
 * languages.class.php). The common skeleton (directory iteration, name-
 * regex validation, marker-file check, name/version/uri/author/author_uri/
 * PEM-extension-id extraction) is ~95% identical across the 3 methods;
 * real per-type differences (language charset conversion, plugin's
 * webmaster-gated hasSettings flag) are handled per-type below rather than
 * forced into a false-generic shape.
 *
 * Every string field returned is raw, untrusted scanned data (P59 Batch 0
 * item 3) -- deliberately not HTML-escaped here. Escaping belongs at each
 * real consumer's own sink: Latte's auto-escape for a bare template print,
 * or an explicit |htmlspecialchars where a template hand-builds a raw-HTML
 * fragment (e.g. plugins_installed.latte/themes_installed.latte's
 * $author/$version composition) that itself prints with |noescape.
 *
 * Plugin/Theme read `plugin.json`/`theme.json` -- there is no legacy
 * `main.inc.php`/`themeconf.inc.php` header-comment scanning anywhere in
 * this codebase, not even as a fallback -- a plain, tolerant
 * `json_decode()`, not a schema-validated `PluginConfig\PluginManifest`/
 * `ThemeManifest::fromArray()` read; see `scanPlugin()`/`scanTheme()`'s
 * own docblocks for why. Language reads `common.po` (gettext), never the
 * legacy `common.lang.php` convention.
 */
final class ExtensionScanner
{
    /**
     * Dispatch-by-type entry point for the handful of real callers that
     * genuinely don't know $type statically (Admin\Extensions\
     * ExtensionUpdateChecker's own cross-ExtensionType update-checking
     * loops) -- every other real caller already knows which type it wants
     * and should call scanPlugins()/scanThemes()/scanLanguages() directly
     * instead.
     *
     * @return array<string, PluginScanRow|ThemeScanRow|LanguageScanRow>
     *   keyed by extension id (directory name)
     */
    public function scan(ExtensionType $type, UrlServiceInterface $urlService, Lang $lang, Paths $paths, CurrentUser $currentUser, EventDispatcher $eventDispatcher, CurrentConfig $currentConfig, EntityManagerInterface $entityManager, ?string $targetCharset = null): array
    {
        return match ($type) {
            ExtensionType::Plugin => $this->scanPlugins($paths, $currentUser, $currentConfig),
            ExtensionType::Theme => $this->scanThemes($urlService, $paths, $eventDispatcher, $currentConfig, $currentUser, $entityManager),
            ExtensionType::Language => $this->scanLanguages($paths, $currentConfig, $entityManager, $targetCharset),
        };
    }

    /**
     * @return array<string, PluginScanRow> keyed by extension id (directory name)
     */
    public function scanPlugins(Paths $paths, CurrentUser $currentUser, CurrentConfig $currentConfig): array
    {
        $found = [];
        foreach ($this->scanDirectoryEntries(ExtensionType::Plugin, $paths, $currentConfig) as $file) {
            $entry = $this->scanPlugin($file, $paths, $currentUser);
            if ($entry !== null) {
                $found[$file] = $entry;
            }
        }

        return $found;
    }

    /**
     * @return array<string, ThemeScanRow> keyed by extension id (directory name)
     */
    public function scanThemes(UrlServiceInterface $urlService, Paths $paths, EventDispatcher $eventDispatcher, CurrentConfig $currentConfig, CurrentUser $currentUser, EntityManagerInterface $entityManager): array
    {
        $found = [];
        foreach ($this->scanDirectoryEntries(ExtensionType::Theme, $paths, $currentConfig) as $file) {
            $entry = $this->scanTheme($file, $urlService, $paths, $eventDispatcher, $currentConfig, $currentUser, $entityManager);
            if ($entry !== null) {
                $found[$file] = $entry;
            }
        }

        return $found;
    }

    /**
     * $lang is vestigial (scanLanguage() reads common.po's own header
     * fields directly, never anything from Lang) -- kept on this public
     * signature rather than removed, matching the same "stable seam"
     * precedent `PemCatalog` already established: real callers construct
     * this method's arguments identically to scanPlugins()/scanThemes(),
     * and a bare unused parameter costs nothing to keep vs. a multi-file
     * blast radius to drop. $entityManager is likewise now unused here --
     * kept for the same reason, since it stays required on scanThemes()'s
     * own signature and every real caller already constructs both scan
     * methods' arguments identically.
     *
     * @return array<string, LanguageScanRow> keyed by extension id (directory name)
     */
    public function scanLanguages(Paths $paths, CurrentConfig $currentConfig, EntityManagerInterface $entityManager, ?string $targetCharset = null): array
    {
        $found = [];
        foreach ($this->scanDirectoryEntries(ExtensionType::Language, $paths, $currentConfig) as $file) {
            $entry = $this->scanLanguage($file, $targetCharset, $paths);
            if ($entry !== null) {
                $found[$file] = $entry;
            }
        }

        // Piwigo\Html\HtmlService::nameCompare() is the shared cross-domain
        // comparator every other real name-sort call site in this codebase
        // uses (Category rows, plugin/theme rows, ...) -- but it takes
        // `array<string, mixed> $a/$b` (Core\HtmlRenderingInterface's own
        // real, still-generic contract), so it doesn't fit a real
        // LanguageScanRow object directly. Inlined here rather than
        // wrapping each row back into an array just to satisfy that
        // signature -- same strcmp()-on-strtolower() logic, no behavior
        // change.
        uasort($found, static fn (LanguageScanRow $a, LanguageScanRow $b): int => strcmp(strtolower($a->name), strtolower($b->name)));

        return $found;
    }

    /**
     * @return list<string> valid directory-entry names under $type's own
     *   scan directory, already filtered by the shared name-regex check
     */
    private function scanDirectoryEntries(ExtensionType $type, Paths $paths, CurrentConfig $currentConfig): array
    {
        $dir = opendir($type->scanDirectory($paths, $currentConfig));
        if ($dir === false) {
            return [];
        }

        $entries = [];
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (! (bool) preg_match('/^[a-zA-Z0-9-_]+$/', $file)) {
                continue;
            }
            $entries[] = $file;
        }
        closedir($dir);

        return $entries;
    }

    /**
     * Reads `plugin.json` -- no `main.inc.php` header-comment scanning,
     * no legacy fallback. A plain, tolerant `json_decode()` (not
     * `PluginConfig\PluginManifest::fromArray()` + full `opis/json-schema`
     * validation): this is a display-listing read, not an activation
     * gate -- real validation already happens inside `PluginConfig\
     * PluginRegistry::install()`/`activate()`, and a malformed
     * `plugin.json` here should degrade to "not found" rather than break
     * a whole admin listing page.
     */
    private function scanPlugin(string $pluginId, Paths $paths, CurrentUser $currentUser): ?PluginScanRow
    {
        $path = PluginLoader::pluginsPath($paths) . $pluginId;
        if (! is_dir($path) || is_link($path) || ! file_exists($path . '/plugin.json')) {
            return null;
        }

        $contents = file_get_contents($path . '/plugin.json');
        $data = $contents !== false ? json_decode($contents, true) : null;
        if (! is_array($data)) {
            return null;
        }

        $name = is_string($data['name'] ?? null) ? $data['name'] : $pluginId;
        $version = is_string($data['version'] ?? null) ? $data['version'] : '0';
        $uri = is_string($data['homepage'] ?? null) ? $data['homepage'] : '';
        $description = is_string($data['description'] ?? null) ? $data['description'] : '';
        $author = is_string($data['author'] ?? null) ? $data['author'] : '';

        $hasSettingsRaw = $data['hasSettings'] ?? false;
        $hasSettings = $hasSettingsRaw === true
            || ($hasSettingsRaw === 'webmaster' && $currentUser->get()->status === UserStatus::Webmaster);

        $authorUri = is_string($data['authorUri'] ?? null) && $data['authorUri'] !== '' ? $data['authorUri'] : null;
        $extension = $this->extractExtensionId($uri);

        // Raw scanned values, deliberately NOT pre-escaped here: every
        // string field is untrusted (author-controlled plugin.json
        // content), and each real consumer decides how to escape it for
        // its own sink -- Latte's auto-escape for a bare template print,
        // an explicit |htmlspecialchars at the point a hand-built raw-HTML
        // fragment embeds it (plugins_installed.latte's $author/$version
        // composition). Pre-escaping here unconditionally double-escaped
        // every bare print (updates_ext.latte's currentVersion/newVersion,
        // plugins_installed.latte's name/desc).
        return new PluginScanRow(
            name: $name,
            version: $version,
            uri: $uri,
            description: $description,
            author: $author,
            hasSettings: $hasSettings,
            authorUri: $authorUri,
            extension: $extension,
        );
    }

    /**
     * Reads `theme.json` -- no `themeconf.inc.php` header-comment
     * scanning, no legacy fallback. Same plain, tolerant
     * `json_decode()` rationale as `scanPlugin()` above (a display-listing
     * read, not `ThemeRegistry`'s own schema-validated activation gate).
     * `activable` is never set here (no `ThemeManifest` equivalent, see
     * below) -- stays declared `?:` for callers that already read it
     * defensively.
     */
    private function scanTheme(string $themeId, UrlServiceInterface $urlService, Paths $paths, EventDispatcher $eventDispatcher, CurrentConfig $currentConfig, CurrentUser $currentUser, EntityManagerInterface $entityManager): ?ThemeScanRow
    {
        $path = ExtensionType::Theme->scanDirectory($paths, $currentConfig) . $themeId;
        if (! is_dir($path) || ! file_exists($path . '/theme.json')) {
            return null;
        }

        $contents = file_get_contents($path . '/theme.json');
        $data = $contents !== false ? json_decode($contents, true) : null;
        if (! is_array($data)) {
            return null;
        }

        $name = is_string($data['name'] ?? null) ? $data['name'] : $themeId;
        $version = is_string($data['version'] ?? null) ? $data['version'] : '0';
        $uri = is_string($data['homepage'] ?? null) ? $data['homepage'] : '';
        $description = is_string($data['description'] ?? null) ? $data['description'] : '';
        $author = is_string($data['author'] ?? null) ? $data['author'] : '';

        $authorUri = is_string($data['authorUri'] ?? null) && $data['authorUri'] !== '' ? $data['authorUri'] : null;
        $extension = $this->extractExtensionId($uri);
        $parent = is_string($data['parent'] ?? null) ? $data['parent'] : null;
        $useStandardPages = is_bool($data['useStandardPages'] ?? null) ? $data['useStandardPages'] : null;
        // ThemeManifest has no 'activable' field either -- no bundled or
        // ported theme has ever needed it, and ThemesInstalledPageRenderer's
        // own registry-merge fallback already defaults an unset key to
        // "activable", matching a real theme.json-scanned entry's own
        // always-null $activable here exactly.

        $screenshotPath = $path . '/screenshot.png';
        if (file_exists($screenshotPath)) {
            $screenshot = $screenshotPath;
        } else {
            $adminTheme = new PreferencesService(new UserRepository($entityManager, $eventDispatcher, $currentConfig), $currentUser)
                ->getAdminThemePref() ?? $currentConfig->adminTheme;
            $screenshot = $urlService->getRootUrl() . 'themes/admin/'
                . $adminTheme
                . '/images/missing_screenshot.png';
        }

        $adminUri = file_exists($path . '/admin/admin.inc.php')
            ? $urlService->getRootUrl() . 'admin.php?page=theme&theme=' . $themeId
            : null;

        // Raw scanned values, deliberately NOT pre-escaped here -- same
        // reasoning as scanPlugin() above: each real consumer (Latte's
        // auto-escape for a bare print, an explicit |htmlspecialchars at
        // the point themes_installed.latte hand-builds a raw-HTML
        // fragment) decides how to escape it for its own sink.
        return new ThemeScanRow(
            id: $themeId,
            name: $name,
            version: $version,
            uri: $uri,
            description: $description,
            author: $author,
            // ThemeManifest deliberately has no 'mobile' field (see its own
            // docblock) -- "which installed theme serves mobile" stays a
            // pure admin/config pairing, never a manifest-declared fact.
            mobile: false,
            screenshot: $screenshot,
            authorUri: $authorUri,
            extension: $extension,
            parent: $parent,
            useStandardPages: $useStandardPages,
            adminUri: $adminUri,
        );
    }

    /**
     * Header parsing targets this rewrite's real common.po format (see
     * ExtensionType::Language::markerFilenames()'s docblock for how this
     * differs from the legacy common.lang.php format it replaces): the PO
     * header block's "X-Piwigo-Language-Name" field, not the old
     * "Language Name:" comment convention -- confirmed via direct read of
     * every bundled locale's common.po. That header carries no version/
     * author/URI fields at all (bundled core languages version with core,
     * they aren't independently-versioned PEM packages the way plugins/
     * themes are) -- 'version' defaults to AppInfo::VERSION rather than the
     * old '0' placeholder, since "0" would incorrectly flag every bundled
     * language as "outdated" the moment ExtensionUpdateChecker compares it
     * against any real PEM revision.
     */
    private function scanLanguage(string $languageId, ?string $targetCharset, Paths $paths): ?LanguageScanRow
    {
        $path = $paths->root . 'language/' . $languageId;
        if (! is_dir($path) || is_link($path) || ! file_exists($path . '/common.po')) {
            return null;
        }

        $targetCharset = strtolower($targetCharset ?? 'utf-8');

        $name = $languageId;
        $lines = file($path . '/common.po');
        if ($lines === false) {
            return null;
        }
        $data = implode('', $lines);

        if ((bool) preg_match('/"X-Piwigo-Language-Name:\s*(.+?)\\\\n"/', $data, $val)) {
            $name = trim($val[1]);
            $converted = CharsetHelper::convertCharset($name, 'utf-8', $targetCharset);
            if ($converted !== false) {
                $name = $converted;
            }
        }
        // The old common.lang.php convention crammed regional
        // disambiguation directly into the name ("English [UK]", "Français
        // [FR]") -- the .po migration correctly split this into separate
        // X-Piwigo-Language-Name/X-Piwigo-Country headers, but nothing
        // recombined them for display, silently losing the regional
        // disambiguation admins need to tell e.g. en_UK from en_US apart.
        // Restore it (same fix as the old languages::get_fs_languages()).
        if ((bool) preg_match('/"X-Piwigo-Country:\s*(.+?)\\\\n"/', $data, $val)) {
            $country = trim($val[1]);
            $convertedCountry = CharsetHelper::convertCharset($country, 'utf-8', $targetCharset);
            if ($convertedCountry !== false) {
                $country = $convertedCountry;
            }
            if ($country !== '') {
                $name .= ' (' . $country . ')';
            }
        }

        // Raw scanned value, deliberately NOT pre-escaped -- same reasoning
        // as scanPlugin()/scanTheme() above.
        return new LanguageScanRow(
            name: $name,
            code: $languageId,
            version: AppInfo::VERSION,
            uri: '',
            author: '',
        );
    }

    private function extractExtensionId(string $uri): ?string
    {
        if ($uri === '' || ! str_contains($uri, 'extension_view.php?eid=')) {
            return null;
        }
        [, $extension] = explode('extension_view.php?eid=', $uri);

        return is_numeric($extension) ? $extension : null;
    }
}
