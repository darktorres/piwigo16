<?php

declare(strict_types=1);

namespace Piwigo\Validation;

use Piwigo\Core\ValidationPattern;

/**
 * Typed replacement for `check_input_parameter()`
 * (include/functions.inc.php) -- the request-input hacking-attempt guard
 * used at 43 call sites app-wide. Those call sites are left untouched;
 * `check_input_parameter()` becomes a thin free-function delegate to
 * `validate()` (same shape as `get_ephemeral_key()`).
 *
 * Calls the free function `fatal_error()` on a failed check rather than
 * throwing -- L1Infrastructure may not depend on L3Presentation's
 * HtmlService (deptrac), and every real call site already relies on
 * fatal_error()'s `never` return type to halt the request exactly as
 * before.
 */
final class InputValidator
{
    /**
     * @param array<int|string, mixed> $paramArray
     */
    public function validate(string $paramName, array $paramArray, bool $isArray, string $pattern, bool $mandatory = false): ?true
    {
        $paramValue = $paramArray[$paramName] ?? null;

        // it's ok if the input parameter is null
        if (self::emptyValue($paramValue)) {
            if ($mandatory) {
                fatal_error('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
            }
            return true;
        }

        if ($isArray) {
            if (! is_array($paramValue)) {
                fatal_error('[Hacking attempt] the input parameter "' . $paramName . '" should be an array');
            }

            foreach ($paramValue as $key => $itemToCheck) {
                // a non-scalar item (e.g. an unexpected nested array) has no
                // sane string form to validate against $pattern -- that's a
                // malformed/hacking-attempt input in its own right.
                if (! is_scalar($itemToCheck)) {
                    fatal_error('[Hacking attempt] an item is not valid in input parameter "' . $paramName . '"');
                }

                if (! (bool) preg_match(ValidationPattern::ID, (string) $key) || ! (bool) preg_match($pattern, (string) $itemToCheck)) {
                    fatal_error('[Hacking attempt] an item is not valid in input parameter "' . $paramName . '"');
                }
            }
        } else {
            if (! is_scalar($paramValue)) {
                fatal_error('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
            }

            if (! (bool) preg_match($pattern, (string) $paramValue)) {
                fatal_error('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
            }
        }

        return null;
    }

    /**
     * Matches empty()'s exact truthiness semantics -- required since
     * empty() itself is disallowed by this project's strict PHPStan rules.
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0' || $value === false || $value === [];
    }
}
