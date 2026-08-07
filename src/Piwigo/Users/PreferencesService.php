<?php

declare(strict_types=1);

namespace Piwigo\Users;

/**
 * Per-user preferences: a single JSON object on user_infos.preferences,
 * mirrored into `CurrentUser::get()->preferences` for the lifetime of the
 * request. Constructor-injects UserRepository, plain constructor injection
 * (same shape as PermalinkService/GroupService).
 */
final readonly class PreferencesService
{
    public function __construct(
        private UserRepository $repo,
        private CurrentUser $currentUser,
    ) {}

    public function save(): void
    {
        $user = $this->currentUser->get();

        $this->repo->savePreferences($user->id, $user->preferences);
    }

    /**
     * @param mixed $value save() json_encode()s the whole preferences
     *   array, so this isn't limited to strings -- real callers pass bool
     *   (admin.php), int (password.php's timestamp), and array
     *   (functions_search.inc.php's filter list) too
     */
    public function updateParam(string $param, mixed $value): void
    {
        if ($value === 'true') {
            $value = true;
        } elseif ($value === 'false') {
            $value = false;
        }

        $preferences = $this->currentUser->get()
            ->preferences;
        $preferences[$param] = $value;
        $this->currentUser->set($this->currentUser->get()->withPreferences($preferences));

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

        $preferences = $this->currentUser->get()
            ->preferences;
        foreach ($paramList as $param) {
            unset($preferences[$param]);
        }
        $this->currentUser->set($this->currentUser->get()->withPreferences($preferences));

        $this->save();
    }

    public function getParam(string $param, mixed $default = null): mixed
    {
        return $this->currentUser->get()
            ->preferences[$param] ?? $default;
    }
}
