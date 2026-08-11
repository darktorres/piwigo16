<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\UserListPageRenderer::render()}. `$showAddUser` is
 * genuinely optional -- the original code only ever assigns
 * `show_add_user` when true, omitted here (not present as `false`) to
 * match that exact original behavior. `$nbUsersByStatus`/
 * `$nbUsersByLevel` keep the original's mixed per-key shape (a plain
 * label string for a status/level with zero users, an
 * `array{name, counter}` for one with real users) rather than forcing a
 * uniform shape the original code never had.
 */
final readonly class UserListPageContext implements TemplatePageContext
{
    /**
     * @param list<array{id: int, name: string, counter: int}> $groupsForFilter
     * @param array<array-key, string> $themeOptions
     * @param array<string, string> $languageOptions
     * @param array<int, string> $associationOptions
     * @param array<string, string> $labelOfStatus
     * @param array<string, string> $prefStatusOptions
     * @param array<string, string|array{name: string, counter: int}> $nbUsersByStatus
     * @param array<int, string> $levelOptions
     * @param array<int, string|array{name: string, counter: int}> $nbUsersByLevel
     */
    public function __construct(
        public array $groupsForFilter,
        public string $registerDates,
        public string $adminPageTitle,
        public bool $activateComments,
        public bool $doublePassword,
        public string $uHistory,
        public string $pwgToken,
        public int $nbImagePage,
        public int $recentPeriod,
        public array $themeOptions,
        public string $themeSelected,
        public array $languageOptions,
        public string $languageSelected,
        public array $associationOptions,
        public string $protectedUsers,
        public string $passwordProtectedUsers,
        public int $guestUser,
        public ?string $filterGroup,
        public ?string $searchInput,
        public int $connectedUser,
        public string $connectedUserStatus,
        public int $owner,
        public string $ownerUsername,
        public bool $showAddUser,
        public array $labelOfStatus,
        public array $prefStatusOptions,
        public string $prefStatusSelected,
        public array $nbUsersByStatus,
        public array $levelOptions,
        public int $levelSelected,
        public array $nbUsersByLevel,
        public string $groupsArrId,
        public string $groupsArrName,
        public int $guestId,
        public string $viewSelector,
        public int $pagination,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'groups_for_filter' => $this->groupsForFilter,
            'register_dates' => $this->registerDates,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'ACTIVATE_COMMENTS' => $this->activateComments,
            'Double_Password' => $this->doublePassword,
            'U_HISTORY' => $this->uHistory,
            'CSRF_TOKEN' => $this->pwgToken,
            'NB_IMAGE_PAGE' => $this->nbImagePage,
            'RECENT_PERIOD' => $this->recentPeriod,
            'theme_options' => $this->themeOptions,
            'theme_selected' => $this->themeSelected,
            'language_options' => $this->languageOptions,
            'language_selected' => $this->languageSelected,
            'association_options' => $this->associationOptions,
            'protected_users' => $this->protectedUsers,
            'password_protected_users' => $this->passwordProtectedUsers,
            'guest_user' => $this->guestUser,
            'filter_group' => $this->filterGroup,
            'search_input' => $this->searchInput,
            'connected_user' => $this->connectedUser,
            'connected_user_status' => $this->connectedUserStatus,
            'owner' => $this->owner,
            'owner_username' => $this->ownerUsername,
            'label_of_status' => $this->labelOfStatus,
            'pref_status_options' => $this->prefStatusOptions,
            'pref_status_selected' => $this->prefStatusSelected,
            'nb_users_by_status' => $this->nbUsersByStatus,
            'level_options' => $this->levelOptions,
            'level_selected' => $this->levelSelected,
            'nb_users_by_level' => $this->nbUsersByLevel,
            'groups_arr_id' => $this->groupsArrId,
            'groups_arr_name' => $this->groupsArrName,
            'guest_id' => $this->guestId,
            'view_selector' => $this->viewSelector,
            'pagination' => $this->pagination,
        ];

        if ($this->showAddUser) {
            $result['show_add_user'] = true;
        }

        return $result;
    }
}
