<?php

declare(strict_types=1);

namespace Piwigo\Feed;

final class FeedHelper
{
    public static function datetimeToTs(string $datetime): int
    {
        return (int) strtotime($datetime);
    }

    public static function tsToIso8601(int $ts): string
    {
        $tz = date('O', $ts);
        $tz = substr($tz, 0, -2) . ':' . substr($tz, -2);
        return date('Y-m-d\\TH:i:s', $ts) . $tz;
    }
}
