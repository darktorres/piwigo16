<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\CurrentConfig;

/**
 * Pure pagination math -- no template/DB dependency.
 *
 * Lives in `Piwigo\Core` (L1Infrastructure) rather than a
 * Presentation-layer namespace: this class has no imports and no
 * dependency on `Template` (unlike `PageHeaderRenderer`/
 * `PageTailRenderer`), and `Category\CategoryCatsRenderer`
 * (L2aCoreDomain) needs to construct it directly.
 */
final class PaginationService
{
    public function __construct(
        private readonly CurrentConfig $currentConfig,
    ) {}

    /**
     * @param int|string $nbElement real callers pass numeric strings too
     *   (see docblock on the original create_navigation_bar())
     * @param int|string $start may be a numeric string: index.php/
     *   category_cats.inc.php pass a raw preg_match() capture
     *   (include/functions_url.inc.php), and admin/rating.php/
     *   admin/batch_manager.php pass $_GET/$_REQUEST directly after only
     *   an is_numeric() check
     * Assigned wholesale into Smarty templates at every real call site (never
     * read key-by-key in PHP) -- an empty return means "single page, no
     * navigation needed"; each key below is independently optional rather
     * than a value object, matching the .tpl files' own per-key isset()
     * checks.
     *
     * @return array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int}
     */
    public function createNavigationBar(
        string $url,
        int|string $nbElement,
        int|string $start,
        int $nbElementPage,
        bool $cleanUrl = false,
        string $paramName = 'start'
    ): array {
        // real callers pass numeric strings here (see docblock); all downstream
        // logic is pure arithmetic/comparison, so normalize once at the entry
        $nbElement = (int) $nbElement;
        $start = (int) $start;

        $navbar = [];
        $pages_around = $this->currentConfig->paginatePagesAround();
        $start_str = $cleanUrl ? '/' . $paramName . '-' : (! str_contains($url, '?') ? '?' : '&amp;') . $paramName . '=';

        if ($start < 0) {
            $start = 0;
        }

        // navigation bar useful only if more than one page to display !
        if ($nbElement > $nbElementPage) {
            $url_start = $url . $start_str;

            $cur_page = $navbar['CURRENT_PAGE'] = (float) $start / (float) $nbElementPage + 1.0;
            $maximum = (int) ceil($nbElement / $nbElementPage);

            $start = (float) $nbElementPage * round((float) $start / (float) $nbElementPage);
            $previous = $start - (float) $nbElementPage;
            $next = $start + (float) $nbElementPage;
            $last = ($maximum - 1) * $nbElementPage;

            // link to first page and previous page?
            // $cur_page can be a non-integer float ($start / $nbElementPage + 1,
            // computed before $start is normalized to a page boundary below) --
            // compare numerically as floats, matching the original's loose "!="
            // exactly, rather than a type-sensitive "!==".
            if ($cur_page !== 1.0) {
                $navbar['URL_FIRST'] = $url;
                $navbar['URL_PREV'] = $previous > 0 ? $url_start . (string) $previous : $url;
            }
            // link on next page and last page?
            if ($cur_page !== (float) $maximum) {
                $navbar['URL_NEXT'] = $url_start . (string) ($next < $last ? $next : $last);
                $navbar['URL_LAST'] = $url_start . $last;
            }

            // pages to display
            $navbar['pages'] = [];
            $navbar['pages'][1] = $url;
            for ($i = (int) max(floor($cur_page) - (float) $pages_around, 2), $stop = min(ceil($cur_page) + (float) $pages_around + 1.0, (float) $maximum);
                $i < $stop; $i++) {
                $navbar['pages'][$i] = $url . $start_str . (($i - 1) * $nbElementPage);
            }
            $navbar['pages'][$maximum] = $url_start . $last;
            $navbar['NB_PAGE'] = $maximum;
        }
        return $navbar;
    }
}
