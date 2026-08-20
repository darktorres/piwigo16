<?php

declare(strict_types=1);

namespace Piwigo\Core;

final class TimingHelper
{
    public static function microSeconds(): string
    {
        $t1 = explode(' ', microtime());
        $t2 = explode('.', $t1[0]);
        return $t1[1] . substr($t2[1], 0, 6);
    }

    /**
     * A float value corresponding to the number of seconds since the unix
     * epoch (1st January 1970), with microsecond precision, e.g.
     * 1052343429.89276600
     */
    public static function getMoment(): float
    {
        return microtime(true);
    }

    /**
     * @return string "$TIME s"
     */
    public static function getElapsedTime(float $start, float $end): string
    {
        return number_format($end - $start, 3, '.', ' ') . ' s';
    }

    /**
     * append a line to RequestMetrics' accumulated debug output
     */
    public static function debug(string $string, RequestMetrics $requestMetrics): void
    {
        $now = explode(' ', microtime());
        $now2 = explode('.', $now[0]);
        // microtime()'s own format ("<fraction> <seconds>", both always numeric)
        // guarantees this concatenation is always a numeric string.
        $now2_float = (float) ($now[1] . '.' . $now2[1]);
        $time = number_format($now2_float - $requestMetrics->requestStart, 3, '.', ' ') . ' s';
        $count_queries = $requestMetrics->countQueries;
        $requestMetrics->addDebugOutput('<p>[' . $time . ', ' . $count_queries . ' queries] : ' . $string . "</p>\n");
    }
}
