<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Per-user preferences: a single serialized blob on user_infos.preferences,
 * mirrored into `CurrentUser::get()->preferences` for the lifetime of the
 * request (Legacy Coupling Retirement Track A batch A3 -- previously
 * `global $user['preferences']`). Constructor-injects UserRepository,
 * plain constructor injection (same shape as PermalinkService/GroupService).
 */
final class PreferencesService
{
    public function __construct(
        private readonly UserRepository $repo,
    ) {}

    public function save(): void
    {
        $currentUser = CurrentUser::get();

        $this->repo->savePreferences($currentUser->id, serialize($currentUser->preferences));
    }

    /**
     * @param mixed $value userprefs_save() serialize()s the whole
     *   preferences array, so this isn't limited to strings -- real
     *   callers pass bool (admin.php), int (password.php's timestamp), and
     *   array (functions_search.inc.php's filter list) too
     */
    public function updateParam(string $param, mixed $value): void
    {
        if ($value === 'true') {
            $value = true;
        } elseif ($value === 'false') {
            $value = false;
        }

        $preferences = CurrentUser::get()->preferences;
        $preferences[$param] = $value;
        CurrentUser::set(CurrentUser::get()->withPreferences($preferences));

        $this->save();
    }

    /**
     * @param string|array<int|string, string> $params
     */
    public function deleteParam(string|array $params): void
    {
        $paramList = is_array($params) ? $params : [$params];
        if ($paramList === []) {
            return;
        }

        $preferences = CurrentUser::get()->preferences;
        foreach ($paramList as $param) {
            unset($preferences[$param]);
        }
        CurrentUser::set(CurrentUser::get()->withPreferences($preferences));

        $this->save();
    }

    public function getParam(string $param, mixed $default = null): mixed
    {
        return CurrentUser::get()->preferences[$param] ?? $default;
    }
}
