<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Per-user preferences: a single serialized blob on user_infos.preferences,
 * mirrored into `global $user['preferences']` for the lifetime of the
 * request. Constructor-injects UserRepository, plain constructor injection
 * (same shape as PermalinkService/GroupService).
 */
final class PreferencesService
{
    public function __construct(
        private readonly UserRepository $repo,
    ) {}

    public function save(): void
    {
        /** @var array<string, mixed> $user */
        global $user;

        $userId = $user['id'] ?? null;
        $userId = is_int($userId) || is_string($userId) ? $userId : 0;

        $this->repo->savePreferences($userId, serialize($user['preferences'] ?? []));
    }

    /**
     * @param mixed $value userprefs_save() serialize()s the whole
     *   preferences array, so this isn't limited to strings -- real
     *   callers pass bool (admin.php), int (password.php's timestamp), and
     *   array (functions_search.inc.php's filter list) too
     */
    public function updateParam(string $param, mixed $value): void
    {
        /** @var array<string, mixed> $user */
        global $user;

        if ($value === 'true') {
            $value = true;
        } elseif ($value === 'false') {
            $value = false;
        }

        $preferences = $user['preferences'] ?? [];
        if (! is_array($preferences)) {
            $preferences = [];
        }
        $preferences[$param] = $value;
        $user['preferences'] = $preferences;

        $this->save();
    }

    /**
     * @param string|array<int|string, string> $params
     */
    public function deleteParam(string|array $params): void
    {
        /** @var array<string, mixed> $user */
        global $user;

        $paramList = is_array($params) ? $params : [$params];
        if ($paramList === []) {
            return;
        }

        $preferences = $user['preferences'] ?? [];
        if (! is_array($preferences)) {
            $preferences = [];
        }
        foreach ($paramList as $param) {
            unset($preferences[$param]);
        }
        $user['preferences'] = $preferences;

        $this->save();
    }

    public function getParam(string $param, mixed $default = null): mixed
    {
        /** @var array<string, mixed> $user */
        global $user;

        $preferences = $user['preferences'] ?? null;

        return is_array($preferences) ? ($preferences[$param] ?? $default) : $default;
    }
}
