<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or trigger_error('Hacking attempt!', E_USER_ERROR);

/**
 * Creates a Unix timestamp from a MySQL datetime string (2005-07-14 23:01:37).
 */
function datetime_to_ts(string $datetime): int
{
    return (int) strtotime($datetime);
}

/**
 * Creates an ISO 8601 date string from a Unix timestamp.
 * Function copied from Dotclear project http://dotclear.net
 */
function ts_to_iso8601(int $ts): string
{
    $tz = date('O', $ts);
    $tz = substr($tz, 0, -2) . ':' . substr($tz, -2);
    return date('Y-m-d\\TH:i:s', $ts) . $tz;
}

// UniversalFeedCreator::$encoding is protected; subclass widens it.
class PiwigoFeedCreator extends UniversalFeedCreator
{
    /** @var string */
    #[\Override]
    public $encoding = 'UTF-8';
}
