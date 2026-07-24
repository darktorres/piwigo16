<?php

declare(strict_types=1);

namespace Piwigo\Section;

/**
 * [SEC-15] Replaces eval($random_url_condition) for the
 * `random_index_redirect` config feature (include/config_default.inc.php)
 * with a declarative, named-condition evaluator -- a config value with DB
 * write access previously got arbitrary PHP execution via eval(). The
 * config's own documented example only ever uses 2 real condition shapes
 * ('' / 'return true;' always-match, 'return is_a_guest();' guest-only);
 * anything else now safely matches nothing rather than executing.
 */
final class RandomIndexRedirectResolver
{
    /**
     * @param array<mixed, mixed> $redirectCandidates url => condition map,
     *   as stored in \Piwigo\Config\CurrentConfig::randomIndexRedirect()
     * @return list<string> urls whose condition matched
     */
    public function resolveCandidates(array $redirectCandidates): array
    {
        $matches = [];
        foreach ($redirectCandidates as $url => $condition) {
            if (! is_string($url)) {
                continue;
            }
            if ($this->conditionMatches($condition)) {
                $matches[] = $url;
            }
        }
        return $matches;
    }

    private function conditionMatches(mixed $condition): bool
    {
        if ($condition === '' || $condition === 'return true;') {
            return true;
        }
        if ($condition === 'return is_a_guest();') {
            return \Piwigo\Auth\AccessControl::isAGuest();
        }
        return false;
    }
}
