<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Piwigo\Language\LanguageService;

final readonly class PreferencesService
{
    public function __construct(
        private LanguageService $languageService,
        private UserRepository $userRepository,
    ) {
    }

    public function getBrowserLanguage(): false|string
    {
        $raw = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
        return self::pickFromAcceptLanguage(
            array_keys($this->languageService->getActiveLanguages()),
            is_string($raw) ? $raw : '',
        );
    }

    /**
     * Pick the best-matching locale code from $availableCodes for the given
     * Accept-Language header. Walks header entries in q-value-descending
     * order; for each entry tries an exact full-locale match first (pt-BR
     * → pt_BR), then a 2-letter prefix fallback (pt → some pt_XX). Returns
     * the matched code as it appeared in $availableCodes, or false.
     *
     * Used at install/upgrade time (against filesystem-discovered locales)
     * and at runtime by getBrowserLanguage() (against DB-registered ones).
     *
     * @param list<string> $availableCodes
     */
    public static function pickFromAcceptLanguage(array $availableCodes, string $rawAcceptLang): false|string
    {
        if ($rawAcceptLang === '') {
            return false;
        }

        $header = strtolower(str_replace('-', '_', $rawAcceptLang));
        preg_match_all(
            '/(([a-z]{1,8})(?:_[a-z0-9]{1,8})*)\s*(?:;\s*q\s*=\s*([01](?:\.[0-9]{0,3})?))?/',
            $header,
            $matches,
        );
        $full  = $matches[1];
        $short = $matches[2];
        if (count($full) === 0) {
            return false;
        }

        $qValues = [];
        foreach ($matches[3] as $i => $qv) {
            $qValues[$i] = $qv === '' ? 1.0 : (float) $qv;
        }
        $indices = range(0, count($qValues) - 1);
        array_multisort($qValues, SORT_DESC, SORT_NUMERIC, $indices, SORT_ASC, SORT_NUMERIC, $full, $short);

        $byKey = [];
        foreach ($availableCodes as $code) {
            $lc     = strtolower($code);
            $prefix = explode('_', $lc, 2)[0];
            $byKey[$lc]     = $code;
            $byKey[$prefix] = $code;
        }

        foreach ($full as $i => $f) {
            if (isset($byKey[$f])) {
                return $byKey[$f];
            }
            $s = $short[$i] ?? '';
            if ($s !== '' && isset($byKey[$s])) {
                return $byKey[$s];
            }
        }

        return false;
    }

    public function userprefsSave(): void
    {
        $user        = CurrentUser::isInitialized() ? CurrentUser::get()->rawAttributes : [];
        $preferences = $user['preferences'] ?? [];

        $this->userRepository->updatePreferences(CurrentUser::get()->id, serialize($preferences));
    }

    public function userprefsUpdateParam(string $param, mixed $value): void
    {
        if ('true' == $value) {
            $value = true;
        } elseif ('false' == $value) {
            $value = false;
        }

        if (CurrentUser::isInitialized()) {
            if (!isset(CurrentUser::get()->rawAttributes['preferences']) || !is_array(CurrentUser::get()->rawAttributes['preferences'])) {
                CurrentUser::get()->rawAttributes['preferences'] = [];
            }
            CurrentUser::get()->rawAttributes['preferences'][$param] = $value;
        }
        $this->userprefsSave();
    }

    /**
     * @param (int|string)[]|string $params
     *
     * @psalm-param 'reset_password_forbidden_until'|non-empty-list<array-key> $params
     */
    public function userprefsDeleteParam(array|string $params): void
    {
        if (!is_array($params)) {
            $params = [$params];
        }

        if (CurrentUser::isInitialized()) {
            if (!isset(CurrentUser::get()->rawAttributes['preferences']) || !is_array(CurrentUser::get()->rawAttributes['preferences'])) {
                CurrentUser::get()->rawAttributes['preferences'] = [];
            }
            foreach ($params as $param) {
                if ($param !== '' && isset(CurrentUser::get()->rawAttributes['preferences'][$param])) {
                    unset(CurrentUser::get()->rawAttributes['preferences'][$param]);
                }
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
        $user        = CurrentUser::isInitialized() ? CurrentUser::get()->rawAttributes : [];
        $preferences = is_array($user['preferences'] ?? null) ? $user['preferences'] : [];

        $key = $param;
        return $preferences[$key] ?? $defaultValue;
    }
}
