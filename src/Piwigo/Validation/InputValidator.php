<?php

declare(strict_types=1);

namespace Piwigo\Validation;

use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
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
 * campaign, Phase 3): `$htmlRenderer` stayed a static-setter collaborator
 * originally because constructor-/parameter-injecting it would ripple
 * across every one of its ~90 real construction sites for zero benefit --
 * but every one of those sites turned out to be a `Request\*Request::
 * fromGlobals()` static factory (P26/SEC-40), with no instance context to
 * receive constructor injection through at all. `HtmlRenderingInterface`
 * is already bound in container.php, so this class needs no explicit
 * wiring any more (RequestBootstrap::configure()'s former
 * `setHtmlRenderer(new HtmlService())` call is gone, autowiring handles
 * it) -- createStatic() is the `@deprecated` transitional bridge those ~90
 * static factories use instead of `new self()` directly.
 */
final class InputValidator
{
    public function __construct(
        private readonly ?HtmlRenderingInterface $htmlRenderer = null,
    ) {}

    /**
     * @deprecated transitional bridge for the ~90 Request DTO static
     * `fromGlobals()` factory methods (Admin/Request/, Controller/.../Request/)
     * that construct this class outside any container-resolved object
     * graph -- there is no instance context for those static factories to
     * receive constructor injection through.
     * Gracefully falls back to a bare, renderer-less instance (the same
     * "never wired up" shape the static-setter version had before any
     * `setHtmlRenderer()` call, still exercised directly by
     * InputValidatorTest.php's own `new InputValidator()` sites) when
     * Kernel hasn't booted -- fatalError() below already handles that
     * case by throwing RuntimeException, matching every request-input
     * rejection test in this codebase that predates this conversion.
     * Delete once every Request DTO itself stops being a static factory
     * (a separate, not-yet-planned initiative -- Request DTOs are their
     * own architectural layer, not part of this campaign's scope).
     */
    public static function createStatic(): self
    {
        if (! Kernel::isBooted()) {
            return new self();
        }

        $instance = Kernel::container()->get(self::class);
        if (! $instance instanceof self) {
            throw new \LogicException('Container returned an unexpected type for ' . self::class);
        }

        return $instance;
    }

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
    public static function fail(string $msg): never
    {
        self::createStatic()->fatalError($msg);
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
