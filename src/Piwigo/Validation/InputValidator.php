<?php

declare(strict_types=1);

namespace Piwigo\Validation;

use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\ValidationPattern;
use RuntimeException;

/**
 * Validates request-input parameters against a regex pattern, rejecting
 * any mismatch as an invalid request parameter.
 *
 * `$htmlRenderer` is optional: callers without an instance context to
 * inject it through (e.g. `Request\*Request::fromGlobals()`/
 * `fromArray()`/`fromArrays()` static factories) pass an explicit
 * `InputValidator` instance instead.
 */
final readonly class InputValidator
{
    public function __construct(
        private ?HtmlRenderingInterface $htmlRenderer = null,
    ) {}

    private function fatalError(string $msg): never
    {
        if ($this->htmlRenderer instanceof HtmlRenderingInterface) {
            $this->htmlRenderer->fatalError($msg);
        }
        throw new RuntimeException($msg);
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
                $this->fatalError('Invalid request parameter "' . $paramName . '"');
            }
            return true;
        }

        if ($isArray) {
            if (! is_array($paramValue)) {
                $this->fatalError('Invalid request parameter "' . $paramName . '"');
            }

            foreach ($paramValue as $key => $itemToCheck) {
                // a non-scalar item (e.g. an unexpected nested array) has no
                // sane string form to validate against $pattern -- that's a
                // malformed input in its own right.
                if (! is_scalar($itemToCheck)) {
                    $this->fatalError('an invalid item in input parameter "' . $paramName . '"');
                }

                if ($pattern === '' || ! (bool) preg_match(ValidationPattern::ID, (string) $key) || ! (bool) preg_match($pattern, (string) $itemToCheck)) {
                    $this->fatalError('an invalid item in input parameter "' . $paramName . '"');
                }
            }
        } else {
            if (! is_scalar($paramValue)) {
                $this->fatalError('Invalid request parameter "' . $paramName . '"');
            }

            if ($pattern === '' || ! (bool) preg_match($pattern, (string) $paramValue)) {
                $this->fatalError('Invalid request parameter "' . $paramName . '"');
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

    public static function checkEmailFormat(?string $mailAddress): bool
    {
        return filter_var($mailAddress, FILTER_VALIDATE_EMAIL) !== false;
    }
}
