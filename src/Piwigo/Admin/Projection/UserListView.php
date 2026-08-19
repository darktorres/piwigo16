<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `user_list.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\UserListPageRenderer::render()}. No `$doublePassword`,
 * `$nbImagePage`, `$recentPeriod`, `$protectedUsers`,
 * `$passwordProtectedUsers`, `$showAddUser`, `$labelOfStatus`, or
 * `$guestUser` field -- the template's own body never references any of
 * them (confirmed against `user_list.js` too: its nb_image_page/
 * recent_period sliders use hardcoded JS value tables, not a
 * server-provided value, and none of the others are read via
 * `pwg_getPageData()` either; `$guestId`, the other guest-id field, is
 * the one this template actually uses).
 */
#[Template('user_list.latte')]
final readonly class UserListView implements View
{
    /**
     * @param list<array{id: int, name: string, counter: int}> $groupsForFilter
     * @param array<array-key, string> $themeOptions
     * @param array<string, string> $languageOptions
     * @param array<int, string> $associationOptions
     * @param array<string, string> $prefStatusOptions
     * @param array<string, string|array{name: string, counter: int}> $nbUsersByStatus
     * @param array<int, string> $levelOptions
     * @param array<int, string|array{name: string, counter: int}> $nbUsersByLevel
     */
    public function __construct(
        public array $groupsForFilter,
        public string $registerDates,
        public bool $activateComments,
        public string $uHistory,
        public string $csrfToken,
        public array $themeOptions,
        public string $themeSelected,
        public array $languageOptions,
        public string $languageSelected,
        public array $associationOptions,
        public ?string $filterGroup,
        public ?string $searchInput,
        public int $connectedUser,
        public string $connectedUserStatus,
        public int $owner,
        public string $ownerUsername,
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
}
