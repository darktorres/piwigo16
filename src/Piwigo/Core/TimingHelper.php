<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8d: pure microtime/elapsed-time helpers relocated from
 * include/functions.inc.php -- no natural existing class home, stateless,
 * matches Piwigo\Auth\AccessControl's static-class precedent.
 */
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
     * append a variable to the $debug global
     *
     * P23 batch 8d: relocated from include/functions.inc.php's
     * pwg_debug(), unchanged logic.
     */
    public static function debug(string $string): void
    {
        /**
         * @var string
         */
        global $debug;
        /**
         * @var float
         */
        global $t2;

        $now = explode(' ', microtime());
        $now2 = explode('.', $now[0]);
        // microtime()'s own format ("<fraction> <seconds>", both always numeric)
        // guarantees this concatenation is always a numeric string.
        $now2_float = (float) ($now[1] . '.' . $now2[1]);
        $time = number_format($now2_float - $t2, 3, '.', ' ') . ' s';
        $debug .= '<p>';
        $debug .= '[' . $time . ', ';
        $count_queries = PageState::current()->countQueries;
        $debug .= $count_queries . ' queries] : ' . $string;
        $debug .= "</p>\n";
    }
}
