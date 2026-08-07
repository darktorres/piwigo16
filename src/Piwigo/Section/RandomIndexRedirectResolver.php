<?php

declare(strict_types=1);

namespace Piwigo\Section;

use Piwigo\Auth\AccessLevelChecker;

/**
 * [SEC-15] Evaluates the `random_index_redirect` config feature
 * (include/config_default.inc.php) via a declarative, named-condition
 * evaluator instead of eval()'ing the condition string -- a config
 * value with DB write access never gets arbitrary PHP execution. The
 * config's own documented example only ever uses 2 real condition
 * shapes ('' / 'return true;' always-match, 'return is_a_guest();'
 * guest-only); anything else safely matches nothing rather than
 * executing.
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
    public function resolveCandidates(AccessLevelChecker $accessLevelChecker, array $redirectCandidates): array
    {
        $matches = [];
        foreach ($redirectCandidates as $url => $condition) {
            if (is_string($url) && $this->conditionMatches($accessLevelChecker, $condition)) {
                $matches[] = $url;
            }
        }
        return $matches;
    }

    private function conditionMatches(AccessLevelChecker $accessLevelChecker, string $condition): bool
    {
        if ($condition === '' || $condition === 'return true;') {
            return true;
        }
        if ($condition === 'return is_a_guest();') {
            return $accessLevelChecker->isAGuest();
        }
        return false;
    }
}
