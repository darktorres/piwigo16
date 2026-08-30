<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Projection\Navbar;

/**
 * Pure pagination math -- no template/DB dependency.
 *
 * Lives in `Piwigo\Core` (L1Infrastructure) rather than a
 * Presentation-layer namespace: this class has no imports and no
 * dependency on `Template` (unlike `PageHeaderRenderer`/
 * `PageTailRenderer`), and `Category\CategoryCatsRenderer`
 * (L2aCoreDomain) needs to construct it directly.
 */
final readonly class PaginationService
{
    public function __construct(
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param int|string $nbElement real callers pass numeric strings too
     *   (see docblock on the original create_navigation_bar())
     * @param int|string $start may be a numeric string: index.php/
     *   category_cats.inc.php pass a raw preg_match() capture
     *   (include/functions_url.inc.php), and admin/rating.php/
     *   admin/batch_manager.php pass $_GET/$_REQUEST directly after only
     *   an is_numeric() check
     * Assigned wholesale into Latte templates at every real call site (never
     * read key-by-key in PHP) -- an empty {@see Navbar} means "single page,
     * no navigation needed"; each field is independently optional, matching
     * the .latte files' own per-key isset() checks.
     */
    public function createNavigationBar(
        string $url,
        int|string $nbElement,
        int|string $start,
        int $nbElementPage,
        bool $cleanUrl = false,
        string $paramName = 'start'
    ): Navbar {
        // real callers pass numeric strings here (see docblock); all downstream
        // logic is pure arithmetic/comparison, so normalize once at the entry
        $nbElement = (int) $nbElement;
        $start = (int) $start;

        $navbar = [];
        $pages_around = $this->currentConfig->paginatePagesAround;
        $start_str = $cleanUrl ? '/' . $paramName . '-' : (! str_contains($url, '?') ? '?' : '&amp;') . $paramName . '=';

        if ($start < 0) {
            $start = 0;
        }

        // navigation bar useful only if more than one page to display !
        if ($nbElement > $nbElementPage) {
            $url_start = $url . $start_str;

            $maximum = (int) ceil($nbElement / $nbElementPage);

            // The page being shown = the page the FIRST displayed element
            // falls on. $start is an offset into the element list, so that is
            // integer division, and the answer is a page number: an int.
            //
            // This is the one definition that survives an off-boundary
            // $start, which is reachable because $start comes straight off
            // the URL and is only clamped at < 0. Two others have existed
            // here and neither does: the 2009 original computed
            // `ceil($start / $per) + 1`, which over-counts (offset 4 of 20
            // per page reports page 2, though every visible element but the
            // last four is on page 1); and from 856b5a2519 (2012) until
            // P58-B3 this exported `$start / $per + 1` UNROUNDED, so offset
            // 30 reported page 2.5 and `{if $page == $navbar->currentPage}`
            // matched nothing at all -- the bar rendered with no page
            // highlighted. That fraction was collateral: the 2012 commit's
            // actual subject was pinning the generated URLs to page
            // boundaries, and it needed a fractional position for the window
            // bounds below, so it reused one variable for both jobs.
            // Clamped to $maximum: $start is not validated against the
            // element count anywhere upstream, so ?start=500 on a 5-page
            // gallery would otherwise report page 26 -- a page absent from
            // the list below, which puts the template right back where the
            // fraction left it. There is no 26th page to be on.
            $currentPage = min(intdiv($start, $nbElementPage) + 1, $maximum);
            $navbar['CURRENT_PAGE'] = $currentPage;

            // The URLs stay on page boundaries -- that is 856b5a2519's own
            // subject and it still holds -- but on the boundary of the page
            // named above, so first/prev/next/last are that page's real
            // neighbours rather than a separately-rounded page's.
            $start = ($currentPage - 1) * $nbElementPage;
            $previous = $start - $nbElementPage;
            $next = $start + $nbElementPage;
            $last = ($maximum - 1) * $nbElementPage;

            // link to first page and previous page?
            if ($currentPage !== 1) {
                $navbar['URL_FIRST'] = $url;
                $navbar['URL_PREV'] = $previous > 0 ? $url_start . (string) $previous : $url;
            }
            // link on next page and last page?
            if ($currentPage !== $maximum) {
                // No cap needed on $next: this branch only runs when
                // $currentPage < $maximum (it is clamped to $maximum above),
                // so $next is at most ($maximum - 1) * $nbElementPage, which
                // is $last. The old `$next < $last ? $next : $last` guarded
                // against an out-of-range $start reaching here, which the
                // clamp now prevents at the source.
                $navbar['URL_NEXT'] = $url_start . (string) $next;
                $navbar['URL_LAST'] = $url_start . $last;
            }

            // pages to display -- a window centred on the current page, which
            // is what $pages_around means. The old floor()/ceil() pair around
            // a fraction made it asymmetric by one on either side depending on
            // where in the page the offset landed.
            $navbar['pages'] = [];
            $navbar['pages'][1] = $url;
            for ($i = max($currentPage - $pages_around, 2), $stop = min($currentPage + $pages_around + 1, $maximum);
                $i < $stop; $i++) {
                $navbar['pages'][$i] = $url . $start_str . (($i - 1) * $nbElementPage);
            }
            $navbar['pages'][$maximum] = $url_start . $last;
            $navbar['NB_PAGE'] = $maximum;
        }
        return Navbar::fromLegacyArray($navbar);
    }
}
