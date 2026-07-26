<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8d: version-string helpers relocated from
 * include/functions.inc.php -- no natural existing class home, stateless.
 */
final class VersionHelper
{
    /**
     * @param string $op one of version_compare()'s comparison operators
     *   ('<', 'lt', '<=', 'le', '>', 'gt', '>=', 'ge', '==', '=', 'eq',
     *   '!=', '<>', 'ne'), or null/'' for the raw -1/0/1 result
     */
    public static function safeVersionCompare(string $a, string $b, ?string $op = null): int|bool
    {
        $replace_chars = (fn (array $m): string => (string) ord(strtolower(is_string($m[1] ?? null) ? $m[1] : '')[0]));

        // add dot before groups of letters (version_compare does the same thing)
        $a = preg_replace('#([0-9]+)([a-z]+)#i', '$1.$2', $a);
        $b = preg_replace('#([0-9]+)([a-z]+)#i', '$1.$2', $b);

        // apply ord() to any single letter
        $a = preg_replace_callback('#\b([a-z]{1})\b#i', $replace_chars, (string) $a);
        $b = preg_replace_callback('#\b([a-z]{1})\b#i', $replace_chars, (string) $b);

        $known_operators = ['<', 'lt', '<=', 'le', '>', 'gt', '>=', 'ge', '==', '=', 'eq', '!=', '<>', 'ne'];
        if ($op === null || $op === '' || ! in_array($op, $known_operators, true)) {
            return version_compare((string) $a, (string) $b);
        } else {
            return version_compare((string) $a, (string) $b, $op);
        }
    }

    /**
     * return the branch from the version. For example version 11.1.2 is on branch 11
     *
     * always takes just the first digits before the first "." -- unlike the
     * pre-migration legacy get_branch_from_version(), whose own comment
     * described a (never-actually-implemented) special case for versions
     * before 11.0.0 taking the first 2 digits (e.g. 2.2.4 on branch 2.2)
     */
    public static function getBranchFromVersion(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 1));
    }
}
