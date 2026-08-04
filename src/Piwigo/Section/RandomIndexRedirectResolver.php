<?php

declare(strict_types=1);

namespace Piwigo\Section;

use Piwigo\Auth\AccessControl;

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
     * @param array<int|string, string> $redirectCandidates url => condition
     *   map, as stored in \Piwigo\Config\CurrentConfig::randomIndexRedirect()
     *   -- PHP casts a purely-numeric string key to int, so a malformed
     *   config entry can arrive int-keyed even though every real key is
     *   meant to be a URL string; skipped defensively rather than trusted.
     * @return list<string> urls whose condition matched
     */
    public function resolveCandidates(AccessControl $accessControl, array $redirectCandidates): array
    {
        $matches = [];
        foreach ($redirectCandidates as $url => $condition) {
            if (is_string($url) && $this->conditionMatches($accessControl, $condition)) {
                $matches[] = $url;
            }
        }
        return $matches;
    }

    private function conditionMatches(AccessControl $accessControl, string $condition): bool
    {
        if ($condition === '' || $condition === 'return true;') {
            return true;
        }
        if ($condition === 'return is_a_guest();') {
            return $accessControl->isAGuest();
        }
        return false;
    }
}
