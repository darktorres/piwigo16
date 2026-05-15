<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Renders inline debug markers into the page footer's "queries list" block.
 * Replaces `Util::pwgDebug()` (retired in Phase 5). The collected HTML
 * fragments live on `PageState->debugLines`; `PageTailRenderer` wires that
 * list to the `QUERIES_LIST` template var when `Config::showGt()` is on.
 */
final class DebugCollector
{
    public function collect(string $marker): void
    {
        $reqTimeFloat = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $startedAt    = is_float($reqTimeFloat) ? $reqTimeFloat : 0.0;
        [$usec, $sec] = explode(' ', microtime());
        $secDecimal   = explode('.', $usec);
        $now          = (float) ($sec . '.' . $secDecimal[1]);
        $elapsed      = number_format($now - $startedAt, 3, '.', ' ') . ' s';

        PageState::current()->debugLines[] =
            '<p>[' . $elapsed . ', ' . PageState::current()->countQueries . ' queries] : ' . $marker . "</p>\n";
    }
}
