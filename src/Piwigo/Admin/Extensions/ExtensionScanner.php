<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\SqlDialect;

/**
 * Filesystem scan for installable extensions, replacing get_fs_plugins()/
 * get_fs_themes()/get_fs_languages() (plugins.class.php/themes.class.php/
 * languages.class.php). The common skeleton (directory iteration, name-
 * regex validation, marker-file check, name/version/uri/author/author_uri/
 * PEM-extension-id extraction, htmlspecialchars() pass) is ~95% identical
 * across the 3 legacy methods; real per-type differences (extra theme
 * header fields, language charset conversion, plugin's webmaster-gated
 * hasSettings flag) are handled per-type below rather than forced into a
 * false-generic shape.
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
     */
    public function scan(ExtensionType $type, UrlServiceInterface $urlService, Lang $lang, ?string $targetCharset = null): array
    {
        $dir = opendir($type->scanDirectory());
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
                ExtensionType::Plugin => $this->scanPlugin($lang, $file),
                ExtensionType::Theme => $this->scanTheme($lang, $file, $urlService),
                ExtensionType::Language => $this->scanLanguage($file, $targetCharset),
            };

            if ($entry !== null) {
                $found[$file] = $entry;
            }
        }
        closedir($dir);

        if ($type === ExtensionType::Language) {
            // Deliberately not \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            // -- this class is designed to be Unit-testable without a full
            // app bootstrap (see ExtensionScannerTest's own docblock), and
            // HtmlService has no mandatory dependencies worth centralizing
            // via the container.
            @uasort($found, new \Piwigo\Html\HtmlService()->nameCompare(...));
        }

        return $found;
    }

    /**
     * @return array{name: string, version: string, uri: string, description: string, author: string, hasSettings: bool, 'author uri'?: string, extension?: string}|null
     */
    private function scanPlugin(Lang $lang, string $pluginId): ?array
    {
        $path = \Piwigo\Admin\PluginLoader::pluginsPath() . $pluginId;
        if (! is_dir($path) || is_link($path) || ! file_exists($path . '/main.inc.php')) {
            return null;
        }

        $plugin = [
            'name' => $pluginId,
            'version' => '0',
            'uri' => '',
            'description' => '',
            'author' => '',
            'hasSettings' => false,
        ];
        $data = file_get_contents($path . '/main.inc.php', false, null, 0, 2048);
        if ($data === false) {
            return null;
        }

        if ((bool) preg_match('|Plugin Name:\s*(.+)|', $data, $val)) {
            $plugin['name'] = trim($val[1]);
        }
        if ((bool) preg_match('|Version:\s*([\w.-]+)|', $data, $val)) {
            $plugin['version'] = trim($val[1]);
        }
        if ((bool) preg_match('|Plugin URI:\s*(https?:\/\/.+)|', $data, $val)) {
            $plugin['uri'] = trim($val[1]);
        }
        $desc = $lang->load('description.txt', $path . '/', [
            'return' => true,
        ]);
        if (is_string($desc) && $desc !== '') {
            $plugin['description'] = trim($desc);
        } elseif ((bool) preg_match('|Description:\s*(.+)|', $data, $val)) {
            $plugin['description'] = trim($val[1]);
        }
        if ((bool) preg_match('|Author:\s*(.+)|', $data, $val)) {
            $plugin['author'] = trim($val[1]);
        }
        if ((bool) preg_match('|Author URI:\s*(https?:\/\/.+)|', $data, $val)) {
            $plugin['author uri'] = trim($val[1]);
        }
        if ((bool) preg_match('/Has Settings:\s*([Tt]rue|[Ww]ebmaster)/', $data, $val)) {
            if (strtolower($val[1]) === 'webmaster') {
                if (\Piwigo\Users\CurrentUser::current()->get()->status === \Piwigo\Users\UserStatus::Webmaster) {
                    $plugin['hasSettings'] = true;
                }
            } else {
                $plugin['hasSettings'] = true;
            }
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
    private function scanTheme(Lang $lang, string $themeId, UrlServiceInterface $urlService): ?array
    {
        $path = ExtensionType::Theme->scanDirectory() . $themeId;
        if (! is_dir($path) || ! file_exists($path . '/themeconf.inc.php')) {
            return null;
        }

        $theme = [
            'id' => $themeId,
            'name' => $themeId,
            'version' => '0',
            'uri' => '',
            'description' => '',
            'author' => '',
            'mobile' => false,
        ];
        $lines = file($path . '/themeconf.inc.php');
        if ($lines === false) {
            return null;
        }
        $data = implode('', $lines);

        if ((bool) preg_match('|Theme Name:\s*(.+)|', $data, $val)) {
            $theme['name'] = trim($val[1]);
        }
        if ((bool) preg_match('|Version:\s*([\w.-]+)|', $data, $val)) {
            $theme['version'] = trim($val[1]);
        }
        if ((bool) preg_match('|Theme URI:\s*(https?:\/\/.+)|', $data, $val)) {
            $theme['uri'] = trim($val[1]);
        }
        $desc = $lang->load('description.txt', $path . '/', [
            'return' => true,
        ]);
        if (is_string($desc) && $desc !== '') {
            $theme['description'] = trim($desc);
        } elseif ((bool) preg_match('|Description:\s*(.+)|', $data, $val)) {
            $theme['description'] = trim($val[1]);
        }
        if ((bool) preg_match('|Author:\s*(.+)|', $data, $val)) {
            $theme['author'] = trim($val[1]);
        }
        if ((bool) preg_match('|Author URI:\s*(https?:\/\/.+)|', $data, $val)) {
            $theme['author uri'] = trim($val[1]);
        }
        $extension = $this->extractExtensionId($theme['uri']);
        if ($extension !== null) {
            $theme['extension'] = $extension;
        }
        if ((bool) preg_match('/["\']parent["\'][^"\']+["\']([^"\']+)["\']/', $data, $val)) {
            $theme['parent'] = $val[1];
        }
        if ((bool) preg_match('/["\']activable["\'].*?(true|false)/i', $data, $val)) {
            $theme['activable'] = SqlDialect::getBoolean($val[1]);
        }
        if ((bool) preg_match('/["\']mobile["\'].*?(true|false)/i', $data, $val)) {
            $theme['mobile'] = SqlDialect::getBoolean($val[1]);
        }
        if ((bool) preg_match('/["\']use_standard_pages["\'].*?(true|false)/i', $data, $val)) {
            $theme['use_standard_pages'] = SqlDialect::getBoolean($val[1]);
        }

        $screenshotPath = $path . '/screenshot.png';
        if (file_exists($screenshotPath)) {
            $theme['screenshot'] = $screenshotPath;
        } else {
            $adminTheme = new \Piwigo\Users\PreferencesService(\Piwigo\Db\EntityManagerFactory::build(\Piwigo\Db\DbConnection::build())->getRepository(\Piwigo\Users\UserInfoEntity::class), \Piwigo\Users\CurrentUser::current())->getParam('admin_theme', \Piwigo\Config\CurrentConfig::adminTheme());
            $theme['screenshot'] = $urlService->getRootUrl() . 'themes/admin/'
                . (is_string($adminTheme) ? $adminTheme : \Piwigo\Config\CurrentConfig::adminTheme())
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
     * ExtensionType::Language::markerFilename()'s docblock for how this
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
    private function scanLanguage(string $languageId, ?string $targetCharset): ?array
    {
        $path = \Piwigo\Core\CurrentPaths::get()->root . 'language/' . $languageId;
        if (! is_dir($path) || is_link($path) || ! file_exists($path . '/common.po')) {
            return null;
        }

        $targetCharset = strtolower($targetCharset ?? \Piwigo\Core\CharsetHelper::getPwgCharset());

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
            $converted = \Piwigo\Core\CharsetHelper::convertCharset($language['name'], 'utf-8', $targetCharset);
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
            $convertedCountry = \Piwigo\Core\CharsetHelper::convertCharset($country, 'utf-8', $targetCharset);
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
