<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\CurrentConfig;

/**
 * Current-script and page-filter helper functions.
 */
final class PageFilterHelper
{
    /**
     * Return the basename of the current script.
     * The lowercase case filename of the current script without extension
     */
    public static function scriptBasename(CurrentConfig $currentConfig): string
    {
        foreach (['SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF'] as $key) {
            $raw = $_SERVER[$key] ?? null;
            if (is_string($raw) && $raw !== '') {
                $filename = strtolower($raw);
                // Production's bootstrap chain (config_default.inc.php)
                // guarantees this key is always set; lightweight test
                // harnesses that build a minimal $conf by hand don't share
                // that guarantee.
                if ($currentConfig->phpExtensionInUrls && StringHelper::getExtension($filename) !== 'php') {
                    continue;
                }
                $basename = basename($filename, '.php');
                /**
                 * @psalm-suppress RedundantCondition Psalm's basename()
                 *   stub returns non-empty-string unconditionally, but
                 *   basename('.php', '.php') genuinely returns '' at
                 *   runtime -- this guard is needed.
                 */
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
    public static function getFilterPageValue(CurrentConfig $currentConfig, string $valueName): ?bool
    {

        $page_name = self::scriptBasename($currentConfig);

        $filter_pages = $currentConfig->filterPages;

        $page_filters = $filter_pages[$page_name] ?? null;
        if ($page_filters !== null && array_key_exists($valueName, $page_filters->flags)) {
            return $page_filters->flags[$valueName];
        }
        $default_filters = $filter_pages['default'] ?? null;
        return $default_filters !== null ? ($default_filters->flags[$valueName] ?? null) : null;
    }
}
