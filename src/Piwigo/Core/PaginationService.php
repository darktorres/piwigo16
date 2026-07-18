<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Pure pagination math -- no template/DB dependency, ported verbatim
 * from create_navigation_bar() (include/functions.inc.php).
 *
 * P23 batch 8d: moved from Piwigo\Page to Piwigo\Core -- deptrac's `Page`
 * collector is L3Presentation (grouped with PageHeaderRenderer/
 * PageTailRenderer, which genuinely need Template), but this class has
 * zero imports and no such dependency; `Category\CategoryCatsRenderer`
 * (L2aCoreDomain) needs to construct it directly for create_navigation_bar()'s
 * retarget, which the L3 grouping blocked. Reclassifying by namespace
 * (L1Infrastructure, same direction as every other `Piwigo\Core\*`
 * cross-cutting utility) resolves the violation architecturally --
 * cheaper and more honest than a dependency-inversion interface for a
 * class with no real Presentation-layer dependency to invert away from.
 */
final class PaginationService
{
    /**
     * @param int|string $nbElement real callers pass numeric strings too
     *   (see docblock on the original create_navigation_bar())
     * @param int|string $start may be a numeric string: index.php/
     *   category_cats.inc.php pass a raw preg_match() capture
     *   (include/functions_url.inc.php), and admin/rating.php/
     *   admin/batch_manager.php pass $_GET/$_REQUEST directly after only
     *   an is_numeric() check
     * @return array<string, mixed>
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
        $pages_around = \Piwigo\Config\Config::paginatePagesAround();
        $start_str = $cleanUrl ? '/' . $paramName . '-' : (! str_contains($url, '?') ? '?' : '&amp;') . $paramName . '=';

        if ($start < 0) {
            $start = 0;
        }

        // navigation bar useful only if more than one page to display !
        if ($nbElement > $nbElementPage) {
            $url_start = $url . $start_str;

            $cur_page = $navbar['CURRENT_PAGE'] = $start / $nbElementPage + 1;
            $maximum = (int) ceil($nbElement / $nbElementPage);

            $start = $nbElementPage * round($start / $nbElementPage);
            $previous = $start - $nbElementPage;
            $next = $start + $nbElementPage;
            $last = ($maximum - 1) * $nbElementPage;

            // link to first page and previous page?
            // $cur_page can be a non-integer float ($start / $nbElementPage + 1,
            // computed before $start is normalized to a page boundary below) --
            // compare numerically as floats, matching the original's loose "!="
            // exactly, rather than a type-sensitive "!==".
            if ((float) $cur_page !== 1.0) {
                $navbar['URL_FIRST'] = $url;
                $navbar['URL_PREV'] = $previous > 0 ? $url_start . $previous : $url;
            }
            // link on next page and last page?
            if ((float) $cur_page !== (float) $maximum) {
                $navbar['URL_NEXT'] = $url_start . ($next < $last ? $next : $last);
                $navbar['URL_LAST'] = $url_start . $last;
            }

            // pages to display
            $navbar['pages'] = [];
            $navbar['pages'][1] = $url;
            for ($i = (int) max(floor($cur_page) - $pages_around, 2), $stop = min(ceil($cur_page) + $pages_around + 1, $maximum);
                $i < $stop; $i++) {
                $navbar['pages'][$i] = $url . $start_str . (($i - 1) * $nbElementPage);
            }
            $navbar['pages'][$maximum] = $url_start . $last;
            $navbar['NB_PAGE'] = $maximum;
        }
        return $navbar;
    }
}
