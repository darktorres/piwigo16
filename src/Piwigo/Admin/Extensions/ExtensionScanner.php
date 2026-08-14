<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\PluginLoader;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CharsetHelper;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserStatus;

/**
 * Filesystem scan for installable extensions, replacing get_fs_plugins()/
 * get_fs_themes()/get_fs_languages() (plugins.class.php/themes.class.php/
 * languages.class.php). The common skeleton (directory iteration, name-
 * regex validation, marker-file check, name/version/uri/author/author_uri/
 * PEM-extension-id extraction, htmlspecialchars() pass) is ~95% identical
 * across the 3 methods; real per-type differences (language charset
 * conversion, plugin's webmaster-gated hasSettings flag) are handled
 * per-type below rather than forced into a false-generic shape.
 *
 * Plugin/Theme read `plugin.json`/`theme.json` (P27.10: no legacy
 * `main.inc.php`/`themeconf.inc.php` header-comment scanning anywhere in
 * this codebase, not even as a fallback -- a plain, tolerant
 * `json_decode()`, not a schema-validated `PluginConfig\PluginManifest`/
 * `ThemeManifest::fromArray()` read; see `scanPlugin()`/`scanTheme()`'s
 * own docblocks for why). Language reads `common.po` (gettext), never
 * touched by P27.10 -- it was already migrated off the old
 * `common.lang.php` convention before this phase.
 */
final class ExtensionScanner
{
    /**
     * Each of the 3 real entry shapes (scanPlugin()/scanTheme()/
     * scanLanguage()'s own precise return types) is genuinely different --
     * a 3-way `$type is X ? ... : ...` conditional return type here would
     * just repeat all 3 shapes a second time for no reader benefit; every
     * real caller already reads specific keys defensively (`?? null` +
     * an is_*() check), the same cross-domain generic-row-reader pattern
     * used elsewhere for a dispatched-by-type heterogeneous return.
     *
     * @return array<string, array<string, mixed>> keyed by extension id
     *   (directory name)
     *
     * $lang is vestigial (P27.10 dropped scanPlugin()/scanTheme()'s own
     * description.txt load along with the rest of the legacy header-
     * comment format they read it for) -- kept on this public signature
     * rather than removed, matching the same "stable seam" precedent
     * PemCatalog's own P27.9 redesign already established: ~20 real
     * call sites construct this method's arguments today, and a bare
     * unused parameter costs nothing to keep vs. a 20-file blast radius
     * to drop.
     */
    public function scan(ExtensionType $type, UrlServiceInterface $urlService, Lang $lang, Paths $paths, CurrentUser $currentUser, EventDispatcher $eventDispatcher, CurrentConfig $currentConfig, EntityManagerInterface $entityManager, ?string $targetCharset = null): array
    {
        $dir = opendir($type->scanDirectory($paths, $currentConfig));
        if ($dir === false) {
            return [];
        }

        $found = [];
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (! (bool) preg_match('/^[a-zA-Z0-9-_]+$/', $file)) {
                continue;
            }

            $entry = match ($type) {
                ExtensionType::Plugin => $this->scanPlugin($file, $paths, $currentUser),
                ExtensionType::Theme => $this->scanTheme($file, $urlService, $paths, $eventDispatcher, $currentConfig, $currentUser, $entityManager),
                ExtensionType::Language => $this->scanLanguage($file, $targetCharset, $paths),
            };

            if ($entry !== null) {
                $found[$file] = $entry;
            }
        }
        closedir($dir);

        if ($type === ExtensionType::Language) {
            // Deliberately not \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            // -- this class is designed to be Unit-testable without a full
            // app bootstrap (see ExtensionScannerTest's own docblock).
            // HtmlService has 8 required constructor collaborators, but
            // nameCompare() itself is a pure strcmp()-on-strtolower()
            // comparator that touches none of them, so bare/throwaway
            // instances are harmless here.
            @uasort($found, new HtmlService(
                new CurrentConfig(),
                new EventDispatcher(),
                new ProcessCache(),
                new ErrorCollector(new DeploymentPolicy(), $paths),
                new CurrentUser(new CurrentConfig()),
                new CurrentTemplate(),
                new PageState(),
                new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))),
                new CategoryRepository($entityManager, new CurrentConfig()),
                $entityManager,
            )->nameCompare(...));
        }

        return $found;
    }

    /**
     * Reads `plugin.json` -- P27.10 dropped `main.inc.php` header-comment
     * scanning entirely, no legacy fallback. A plain, tolerant
     * `json_decode()` (not `PluginConfig\PluginManifest::fromArray()` +
     * full `opis/json-schema` validation): this is a display-listing
     * read, not an activation gate -- real validation already happens
     * inside `PluginConfig\PluginRegistry::install()`/`activate()`
     * (P27.3), and a malformed `plugin.json` here should degrade to
     * "not found" rather than break a whole admin listing page.
     *
     * @return array{name: string, version: string, uri: string, description: string, author: string, hasSettings: bool, 'author uri'?: string, extension?: string}|null
     */
    private function scanPlugin(string $pluginId, Paths $paths, CurrentUser $currentUser): ?array
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

        $plugin = [
            'name' => is_string($data['name'] ?? null) ? $data['name'] : $pluginId,
            'version' => is_string($data['version'] ?? null) ? $data['version'] : '0',
            'uri' => is_string($data['homepage'] ?? null) ? $data['homepage'] : '',
            'description' => is_string($data['description'] ?? null) ? $data['description'] : '',
            'author' => is_string($data['author'] ?? null) ? $data['author'] : '',
            'hasSettings' => false,
        ];

        $hasSettingsRaw = $data['hasSettings'] ?? false;
        if ($hasSettingsRaw === true) {
            $plugin['hasSettings'] = true;
        } elseif ($hasSettingsRaw === 'webmaster' && $currentUser->get()->status === UserStatus::Webmaster) {
            $plugin['hasSettings'] = true;
        }

        if (is_string($data['authorUri'] ?? null) && $data['authorUri'] !== '') {
            $plugin['author uri'] = $data['authorUri'];
        }

        $extension = $this->extractExtensionId($plugin['uri']);
        if ($extension !== null) {
            $plugin['extension'] = $extension;
        }

        // IMPORTANT SECURITY! hasSettings is bool, not a display string --
        // exclude it from the escaping pass and restore it afterwards.
        $hasSettings = $plugin['hasSettings'];
        unset($plugin['hasSettings']);
        $plugin = array_map(htmlspecialchars(...), $plugin);
        $plugin['hasSettings'] = $hasSettings;

        /** @var array{name: string, version: string, uri: string, description: string, author: string, hasSettings: bool, 'author uri'?: string, extension?: string} $plugin */
        return $plugin;
    }

    /**
     * Reads `theme.json` -- P27.10 dropped `themeconf.inc.php` header-
     * comment scanning entirely, no legacy fallback. Same plain, tolerant
     * `json_decode()` rationale as `scanPlugin()` above (a display-listing
     * read, not `ThemeRegistry`'s own schema-validated activation gate).
     * `activable` is never set here (no `ThemeManifest` equivalent, see
     * below) -- stays declared `?:` for callers that already read it
     * defensively.
     *
     * @return array{
     *   id: string,
     *   name: string,
     *   version: string,
     *   uri: string,
     *   description: string,
     *   author: string,
     *   mobile: bool,
     *   screenshot: string,
     *   'author uri'?: string,
     *   extension?: string,
     *   parent?: string,
     *   activable?: bool,
     *   use_standard_pages?: bool,
     *   admin_uri?: string,
     * }|null
     */
    private function scanTheme(string $themeId, UrlServiceInterface $urlService, Paths $paths, EventDispatcher $eventDispatcher, CurrentConfig $currentConfig, CurrentUser $currentUser, EntityManagerInterface $entityManager): ?array
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

        $theme = [
            'id' => $themeId,
            'name' => is_string($data['name'] ?? null) ? $data['name'] : $themeId,
            'version' => is_string($data['version'] ?? null) ? $data['version'] : '0',
            'uri' => is_string($data['homepage'] ?? null) ? $data['homepage'] : '',
            'description' => is_string($data['description'] ?? null) ? $data['description'] : '',
            'author' => is_string($data['author'] ?? null) ? $data['author'] : '',
            // ThemeManifest deliberately has no 'mobile' field (see its own
            // docblock) -- "which installed theme serves mobile" stays a
            // pure admin/config pairing, never a manifest-declared fact.
            'mobile' => false,
        ];

        if (is_string($data['authorUri'] ?? null) && $data['authorUri'] !== '') {
            $theme['author uri'] = $data['authorUri'];
        }
        $extension = $this->extractExtensionId($theme['uri']);
        if ($extension !== null) {
            $theme['extension'] = $extension;
        }
        if (is_string($data['parent'] ?? null)) {
            $theme['parent'] = $data['parent'];
        }
        if (is_bool($data['useStandardPages'] ?? null)) {
            $theme['use_standard_pages'] = $data['useStandardPages'];
        }
        // ThemeManifest has no 'activable' field either -- no bundled or
        // ported theme has ever needed it, and ThemesInstalledPageRenderer's
        // own registry-merge fallback already defaults an unset key to
        // "activable" (P27.5), matching a real theme.json-scanned entry's
        // behavior here exactly.

        $screenshotPath = $path . '/screenshot.png';
        if (file_exists($screenshotPath)) {
            $theme['screenshot'] = $screenshotPath;
        } else {
            $adminTheme = new PreferencesService(new UserRepository($entityManager, $eventDispatcher, $currentConfig), $currentUser)
                ->getAdminThemePref() ?? $currentConfig->adminTheme;
            $theme['screenshot'] = $urlService->getRootUrl() . 'themes/admin/'
                . $adminTheme
                . '/images/missing_screenshot.png';
        }

        if (file_exists($path . '/admin/admin.inc.php')) {
            $theme['admin_uri'] = $urlService->getRootUrl() . 'admin.php?page=theme&theme=' . $themeId;
        }

        // IMPORTANT SECURITY! (only string fields -- mobile/activable/
        // use_standard_pages are real bools, which htmlspecialchars() can't
        // accept under strict_types)
        foreach ($theme as $key => $value) {
            if (is_string($value)) {
                $theme[$key] = htmlspecialchars($value);
            }
        }

        // Same re-assertion as scanPlugin()'s own $plugin cast above -- the
        // dynamic $key write above loses the precise shape from PHPStan's
        // perspective even though every value stays the same type.
        /** @var array{
         *   id: string,
         *   name: string,
         *   version: string,
         *   uri: string,
         *   description: string,
         *   author: string,
         *   mobile: bool,
         *   screenshot: string,
         *   'author uri'?: string,
         *   extension?: string,
         *   parent?: string,
         *   activable?: bool,
         *   use_standard_pages?: bool,
         *   admin_uri?: string,
         * } $theme
         */
        return $theme;
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
     *
     * @return array{name: string, code: string, version: string, uri: string, author: string}|null
     */
    private function scanLanguage(string $languageId, ?string $targetCharset, Paths $paths): ?array
    {
        $path = $paths->root . 'language/' . $languageId;
        if (! is_dir($path) || is_link($path) || ! file_exists($path . '/common.po')) {
            return null;
        }

        $targetCharset = strtolower($targetCharset ?? 'utf-8');

        $language = [
            'name' => $languageId,
            'code' => $languageId,
            'version' => AppInfo::VERSION,
            'uri' => '',
            'author' => '',
        ];
        $lines = file($path . '/common.po');
        if ($lines === false) {
            return null;
        }
        $data = implode('', $lines);

        if ((bool) preg_match('/"X-Piwigo-Language-Name:\s*(.+?)\\\\n"/', $data, $val)) {
            $language['name'] = trim($val[1]);
            $converted = CharsetHelper::convertCharset($language['name'], 'utf-8', $targetCharset);
            if ($converted !== false) {
                $language['name'] = $converted;
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
                $language['name'] .= ' (' . $country . ')';
            }
        }

        // IMPORTANT SECURITY!
        // Same re-assertion as scanPlugin()'s own $plugin cast above --
        // array_map() doesn't preserve the precise shape from PHPStan's
        // perspective even though every value stays a string.
        /** @var array{name: string, code: string, version: string, uri: string, author: string} $result */
        $result = array_map(htmlspecialchars(...), $language);

        return $result;
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
