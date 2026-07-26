<?php

declare(strict_types=1);

namespace Piwigo\Validation;

use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\ValidationPattern;

/**
 * Typed replacement for `check_input_parameter()`
 * (include/functions.inc.php) -- the request-input hacking-attempt guard
 * used at 43 call sites app-wide. Those call sites are left untouched;
 * `check_input_parameter()` becomes a thin free-function delegate to
 * `validate()` (same shape as `get_ephemeral_key()`).
 *
 * P23 batch 8f-3: `validate()` is this class's own single, near-universal
 * method (81 real construction sites) -- constructor- or parameter-
 * injecting `HtmlRenderingInterface` would ripple across every one of
 * them for zero real benefit, so this uses the same static-setter shape
 * as `Piwigo\Core\Lang::setDefaultLanguageProvider()` instead: set once by
 * include/common.inc.php (legacy, not subject to deptrac), reused by
 * every `validate()` call in the request. Needed because this
 * L1Infrastructure class may not depend on L3Presentation's HtmlService
 * directly (deptrac), and every real call site already relies on
 * fatalError()'s `never` return type to halt the request exactly as
 * before.
 */
final class InputValidator
{
    private static ?HtmlRenderingInterface $htmlRenderer = null;

    public static function setHtmlRenderer(HtmlRenderingInterface $renderer): void
    {
        self::$htmlRenderer = $renderer;
    }

    private static function fatalError(string $msg): never
    {
        if (self::$htmlRenderer instanceof \Piwigo\Core\HtmlRenderingInterface) {
            self::$htmlRenderer->fatalError($msg);
        }
        throw new \RuntimeException($msg);
    }

    /**
     * Public entry point to the same safe-fallback fatal-error mechanism
     * validate() itself uses, for Request DTOs (P27/SEC-40 --
     * `{Module}/Request/{Name}`) whose own rejection reason doesn't fit
     * validate()'s single-parameter-pattern model (a structural/cardinality
     * check, e.g. "at least 2 path segments", rather than one value against
     * one regex). Keeps every request-input rejection path -- whether
     * pattern-based or structural -- unit-testable the same way (throws
     * RuntimeException when no HtmlRenderingInterface is configured, same
     * as every existing validate() call site's own test already relies on).
     */
    public static function fail(string $msg): never
    {
        self::fatalError($msg);
    }

    /**
     * @param array<int|string, mixed> $paramArray
     */
    public function validate(string $paramName, array $paramArray, bool $isArray, string $pattern, bool $mandatory = false): ?true
    {
        $paramValue = $paramArray[$paramName] ?? null;

        // it's ok if the input parameter is null
        if (self::emptyValue($paramValue)) {
            if ($mandatory) {
                self::fatalError('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
            }
            return true;
        }

        if ($isArray) {
            if (! is_array($paramValue)) {
                self::fatalError('[Hacking attempt] the input parameter "' . $paramName . '" should be an array');
            }

            foreach ($paramValue as $key => $itemToCheck) {
                // a non-scalar item (e.g. an unexpected nested array) has no
                // sane string form to validate against $pattern -- that's a
                // malformed/hacking-attempt input in its own right.
                if (! is_scalar($itemToCheck)) {
                    self::fatalError('[Hacking attempt] an item is not valid in input parameter "' . $paramName . '"');
                }

                if ($pattern === '' || ! (bool) preg_match(ValidationPattern::ID, (string) $key) || ! (bool) preg_match($pattern, (string) $itemToCheck)) {
                    self::fatalError('[Hacking attempt] an item is not valid in input parameter "' . $paramName . '"');
                }
            }
        } else {
            if (! is_scalar($paramValue)) {
                self::fatalError('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
            }

            if ($pattern === '' || ! (bool) preg_match($pattern, (string) $paramValue)) {
                self::fatalError('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
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

    /**
     * P23 batch 8d: relocated from include/functions.inc.php's
     * url_check_format(), unchanged logic.
     */
    public static function checkUrlFormat(string $url): bool
    {
        if (str_contains($url, '"')) {
            return false;
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * P23 batch 8d: relocated from include/functions.inc.php's
     * email_check_format(), unchanged logic.
     */
    public static function checkEmailFormat(?string $mailAddress): bool
    {
        return filter_var($mailAddress, FILTER_VALIDATE_EMAIL) !== false;
    }
}
