<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Core\ServiceLocator;
use Piwigo\Core\Util;

final class PreferencesService
{
    public static function get(): self
    {
        return ServiceLocator::get(self::class);
    }

    public function getBrowserLanguage(): false|int|string
    {
        $languageHeaderRaw = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
        $languageHeader    = is_string($languageHeaderRaw) ? $languageHeaderRaw : '';
        if ($languageHeader == '') {
            return false;
        }

        $languageHeader = strtolower(str_replace('-', '_', $languageHeader));
        $matchPattern   = '/(([a-z]{1,8})(?:_[a-z0-9]{1,8})*)\s*(?:;\s*q\s*=\s*([01](?:\.[0-9]{0,3})?))?/';
        $matches        = null;
        preg_match_all($matchPattern, $languageHeader, $matches);
        $acceptLanguagesFull  = $matches[1];
        $acceptLanguagesShort = $matches[2];
        if (!count($acceptLanguagesFull)) {
            return false;
        }

        $qValues = $matches[3];
        foreach ($qValues as $i => $qValue) {
            $qValues[$i] = ($qValues[$i] === '') ? 1 : floatval($qValues[$i]);
        }

        $indices = range(1, count($qValues));
        array_multisort($qValues, SORT_DESC, SORT_NUMERIC, $indices, SORT_ASC, SORT_NUMERIC, $acceptLanguagesFull, $acceptLanguagesShort);

        $languagesAvailable = [];
        foreach (Util::get()->getLanguages() as $languageCode => $languageName) {
            $lowercaseFull   = strtolower((string) $languageCode);
            $lowercaseParts  = explode('_', $lowercaseFull, 2);
            $lowercasePrefix = $lowercaseParts[0];
            $languagesAvailable[$lowercaseFull]   = $languageCode;
            $languagesAvailable[$lowercasePrefix] = $languageCode;
        }

        foreach ($qValues as $i => $qValue) {
            $fullKey  = strtolower($acceptLanguagesFull[$i] ?? '');
            $shortKey = strtolower($acceptLanguagesShort[$i] ?? '');
            if ($fullKey !== '' && array_key_exists($fullKey, $languagesAvailable)) {
                return $languagesAvailable[$fullKey];
            } elseif ($shortKey !== '' && array_key_exists($shortKey, $languagesAvailable)) {
                return $languagesAvailable[$shortKey];
            }
        }

        return false;
    }

    public function userprefsSave(): void
    {
        $user        = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $preferences = $user['preferences'] ?? [];

        ServiceLocator::get(UserRepository::class)
            ->updatePreferences(CurrentUser::get()->id, serialize($preferences));
    }

    public function userprefsUpdateParam(string $param, mixed $value): void
    {
        $user = &$GLOBALS['user'];
        if (!is_array($user)) {
            $user = [];
        }
        if ('true' == $value) {
            $value = true;
        } elseif ('false' == $value) {
            $value = false;
        }

        if (!isset($user['preferences']) || !is_array($user['preferences'])) {
            $user['preferences'] = [];
        }

        $paramKey = $param;
        $user['preferences'][$paramKey] = $value;
        $this->userprefsSave();
    }

    /**
     * @param (int|string)[]|string $params
     *
     * @psalm-param 'reset_password_forbidden_until'|non-empty-list<array-key> $params
     */
    public function userprefsDeleteParam(array|string $params): void
    {
        $user = &$GLOBALS['user'];
        if (!is_array($user)) {
            $user = [];
        }
        if (!is_array($params)) {
            $params = [$params];
        }

        if (!isset($user['preferences']) || !is_array($user['preferences'])) {
            $user['preferences'] = [];
        }
        foreach ($params as $param) {
            $paramKey = $param;
            if ($paramKey !== '' && isset($user['preferences'][$paramKey])) {
                unset($user['preferences'][$paramKey]);
            }
        }

        $this->userprefsSave();
    }

    /**
     * @param (int|string)[]|int|null|string|true $defaultValue
     *
     * @psalm-param 'classic'|'dark'|'line'|5|10|list<array-key>|null|true $defaultValue
     */
    public function userprefsGetParam(string $param, array|string|int|bool|null $defaultValue = null): mixed
    {
        $user        = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $preferences = is_array($user['preferences'] ?? null) ? $user['preferences'] : [];

        $key = $param;
        return $preferences[$key] ?? $defaultValue;
    }
}
