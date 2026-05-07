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
        $languageHeader    = is_scalar($languageHeaderRaw) ? (string) $languageHeaderRaw : '';
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
            if (array_key_exists($acceptLanguagesFull[$i], $languagesAvailable)) {
                return $languagesAvailable[$acceptLanguagesFull[$i]];
            } elseif (array_key_exists($acceptLanguagesShort[$i], $languagesAvailable)) {
                return $languagesAvailable[$acceptLanguagesShort[$i]];
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

    public function userprefsUpdateParam(mixed $param, mixed $value): void
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

        $paramKey = is_scalar($param) ? (string) $param : '';
        $user['preferences'][$paramKey] = $value;
        $this->userprefsSave();
    }

    public function userprefsDeleteParam(mixed $params): void
    {
        $user = &$GLOBALS['user'];
        if (!is_array($user)) {
            $user = [];
        }
        if (!is_array($params)) {
            $params = [$params];
        }
        if (empty($params)) {
            return;
        }

        if (!isset($user['preferences']) || !is_array($user['preferences'])) {
            $user['preferences'] = [];
        }
        foreach ($params as $param) {
            $paramKey = is_scalar($param) ? (string) $param : '';
            if ($paramKey !== '' && isset($user['preferences'][$paramKey])) {
                unset($user['preferences'][$paramKey]);
            }
        }

        $this->userprefsSave();
    }

    public function userprefsGetParam(mixed $param, mixed $defaultValue = null): mixed
    {
        $user        = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $preferences = is_array($user['preferences'] ?? null) ? $user['preferences'] : [];

        $key = is_scalar($param) ? (string) $param : '';
        return $preferences[$key] ?? $defaultValue;
    }
}
