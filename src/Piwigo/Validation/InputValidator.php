<?php

declare(strict_types=1);

namespace Piwigo\Validation;

use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\ValidationPattern;

/**
 * Typed replacement for the legacy `check_input_parameter()` free
 * function (formerly include/functions.inc.php) -- the request-input
 * hacking-attempt guard. That free function (and its include/ home) has
 * since been fully removed; every former call site now calls this
 * class's `validate()` directly (same end state as `get_ephemeral_key()`'s
 * own migration).
 *
 * Container-shared instance (singleton/service-locator elimination
 * campaign, Phase 3): `$htmlRenderer` was originally a static-setter
 * collaborator because constructor-/parameter-injecting it would ripple
 * across every one of its ~90 real construction sites -- every one of
 * those sites turned out to be a `Request\*Request::fromGlobals()`/
 * `fromArray()`/`fromArrays()` static factory (P26/SEC-40), with no
 * instance context to receive constructor injection through directly.
 * Phase 11 sub-phase 11C closed this for real: every one of those ~43
 * real callers (39 pure Request DTOs plus 4 real classes calling this
 * class directly) now takes `InputValidator` as an explicit parameter
 * instead of reaching for a `createStatic()` transitional bridge --
 * `HtmlRenderingInterface` is bound in container.php, so this class
 * itself needs no explicit wiring, only its own callers do.
 */
final class InputValidator
{
    public function __construct(
        private readonly ?HtmlRenderingInterface $htmlRenderer = null,
    ) {}

    private function fatalError(string $msg): never
    {
        if ($this->htmlRenderer instanceof HtmlRenderingInterface) {
            $this->htmlRenderer->fatalError($msg);
        }
        throw new \RuntimeException($msg);
    }

    /**
     * Public entry point to the same safe-fallback fatal-error mechanism
     * validate() itself uses, for Request DTOs (P26/SEC-40 --
     * `{Module}/Request/{Name}`) whose own rejection reason doesn't fit
     * validate()'s single-parameter-pattern model (a structural/cardinality
     * check, e.g. "at least 2 path segments", rather than one value against
     * one regex). Keeps every request-input rejection path -- whether
     * pattern-based or structural -- unit-testable the same way (throws
     * RuntimeException when no HtmlRenderingInterface is configured, same
     * as every existing validate() call site's own test already relies on).
     */
    public function fail(string $msg): never
    {
        $this->fatalError($msg);
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
                $this->fatalError('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
            }
            return true;
        }

        if ($isArray) {
            if (! is_array($paramValue)) {
                $this->fatalError('[Hacking attempt] the input parameter "' . $paramName . '" should be an array');
            }

            foreach ($paramValue as $key => $itemToCheck) {
                // a non-scalar item (e.g. an unexpected nested array) has no
                // sane string form to validate against $pattern -- that's a
                // malformed/hacking-attempt input in its own right.
                if (! is_scalar($itemToCheck)) {
                    $this->fatalError('[Hacking attempt] an item is not valid in input parameter "' . $paramName . '"');
                }

                if ($pattern === '' || ! (bool) preg_match(ValidationPattern::ID, (string) $key) || ! (bool) preg_match($pattern, (string) $itemToCheck)) {
                    $this->fatalError('[Hacking attempt] an item is not valid in input parameter "' . $paramName . '"');
                }
            }
        } else {
            if (! is_scalar($paramValue)) {
                $this->fatalError('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
            }

            if ($pattern === '' || ! (bool) preg_match($pattern, (string) $paramValue)) {
                $this->fatalError('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
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
