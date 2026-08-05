<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8d: current-script/page-filter helpers relocated from
 * include/functions.inc.php -- no natural existing class home, stateless.
 */
final class PageFilterHelper
{
    /**
     * Return the basename of the current script.
     * The lowercase case filename of the current script without extension
     */
    public static function scriptBasename(): string
    {
        foreach (['SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF'] as $key) {
            $raw = $_SERVER[$key] ?? null;
            if (is_string($raw) && $raw !== '') {
                $filename = strtolower($raw);
                // production's bootstrap chain (config_default.inc.php)
                // guarantees this key is always set; lightweight test
                // harnesses that build a minimal $conf by hand don't share
                // that guarantee (confirmed by the Integration test stub
                // this method's real callers used to route through, before
                // P23 batch 8d retargeted them here directly).
                if (\Piwigo\Config\CurrentConfig::current()->phpExtensionInUrls() && StringHelper::getExtension($filename) !== 'php') {
                    continue;
                }
                $basename = basename($filename, '.php');
                if ($basename !== '') {
                    return $basename;
                }
            }
        }
        return '';
    }

    /**
     * Return \Piwigo\Config\CurrentConfig::filterPages() value for the current page
     */
    public static function getFilterPageValue(string $valueName): ?bool
    {

        $page_name = self::scriptBasename();

        $filter_pages = \Piwigo\Config\CurrentConfig::current()->filterPages();

        $page_filters = $filter_pages[$page_name] ?? null;
        if (isset($page_filters[$valueName])) {
            return $page_filters[$valueName];
        }
        $default_filters = $filter_pages['default'] ?? null;
        if (isset($default_filters[$valueName])) {
            return $default_filters[$valueName];
        } else {
            return null;
        }
    }
}
