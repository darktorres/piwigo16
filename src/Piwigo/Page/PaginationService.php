<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Config\Config;

/**
 * Builds the per-page navigation bar shown above paginated lists (gallery,
 * batch manager, comments, admin album/photo lists). Returns an array of
 * template variables: URL_FIRST/PREV/NEXT/LAST, the `pages` map (page
 * number → URL), and CURRENT_PAGE / NB_PAGE for the renderer.
 */
final class PaginationService
{
    /** @return array<string,mixed> */
    public function createNavigationBar(
        string $url,
        int $nbElement,
        int $start,
        int $nbElementPage,
        bool $cleanUrl = false,
        string $paramName = 'start',
    ): array {
        $navbar      = [];
        $pagesAround = Config::paginatePagesAround();
        $startStr    = $cleanUrl ? '/' . $paramName . '-' : (!str_contains($url, '?') ? '?' : '&') . $paramName . '=';

        if ($start < 0) {
            $start = 0;
        }

        if ($nbElement > $nbElementPage) {
            $urlStart = $url . $startStr;
            $curPage  = $navbar['CURRENT_PAGE'] = (float) $start / (float) $nbElementPage + 1.0;
            $maximum  = ceil((float) $nbElement / (float) $nbElementPage);
            $start    = (int) ((float) $nbElementPage * round((float) $start / (float) $nbElementPage));
            $previous = $start - $nbElementPage;
            $next     = $start + $nbElementPage;
            $last     = (int) (($maximum - 1.0) * (float) $nbElementPage);

            if ($curPage != 1) {
                $navbar['URL_FIRST'] = $url;
                $navbar['URL_PREV']  = $previous > 0 ? $urlStart . $previous : $url;
            }
            if ($curPage != $maximum) {
                $navbar['URL_NEXT'] = $urlStart . ($next < $last ? $next : $last);
                $navbar['URL_LAST'] = $urlStart . $last;
            }

            $navbar['pages']    = [];
            $navbar['pages'][1] = $url;
            for ($i = (int) max(floor($curPage) - (float) $pagesAround, 2.0), $stop = (int) min(ceil($curPage) + (float) $pagesAround + 1.0, $maximum); $i < $stop; $i++) {
                $navbar['pages'][$i] = $url . $startStr . (($i - 1) * $nbElementPage);
            }
            $navbar['pages'][(int) $maximum] = $urlStart . $last;
            $navbar['NB_PAGE']               = $maximum;
        }
        return $navbar;
    }
}
