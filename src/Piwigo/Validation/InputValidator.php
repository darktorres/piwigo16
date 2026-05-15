<?php

declare(strict_types=1);

namespace Piwigo\Validation;

use Piwigo\Core\ValidationPattern;
use Piwigo\Html\HtmlService;

/**
 * Validates individual user-supplied request parameters against regex
 * patterns. Failures call `HtmlService::fatalError()` with a "Hacking
 * attempt" message, which renders the standard error page and exits.
 *
 * The keying-by-array convention (`validate('foo', $_GET, false, '/.../')`)
 * mirrors the original procedural call site shape on Util to keep the
 * Phase 5 carve-out mechanical.
 */
final class InputValidator
{
    /** @param array<mixed> $paramArray */
    public function check(string $paramName, array $paramArray, bool $isArray, ?string $pattern, bool $mandatory = false): bool
    {
        $paramValue = null;
        if (isset($paramArray[$paramName])) {
            $paramValue = $paramArray[$paramName];
        }
        if ($paramValue === null || $paramValue === '' || $paramValue === []) {
            if ($mandatory) {
                HtmlService::fatalError('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
            }
            return true;
        }
        if ($isArray) {
            if (!is_array($paramValue)) {
                HtmlService::fatalError('[Hacking attempt] the input parameter "' . $paramName . '" should be an array');
            }
            foreach ($paramValue as $key => $itemToCheck) {
                $effectivePattern = $pattern !== null && $pattern !== '' ? $pattern : '//';
                if (!preg_match(ValidationPattern::ID, (string) $key) || !preg_match($effectivePattern, is_scalar($itemToCheck) ? (string) $itemToCheck : '')) {
                    HtmlService::fatalError('[Hacking attempt] an item is not valid in input parameter "' . $paramName . '"');
                }
            }
            return true;
        }
        $effectivePattern = $pattern !== null && $pattern !== '' ? $pattern : '//';
        if (!preg_match($effectivePattern, is_scalar($paramValue) ? (string) $paramValue : '')) {
            HtmlService::fatalError('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
        }
        return true;
    }
}
