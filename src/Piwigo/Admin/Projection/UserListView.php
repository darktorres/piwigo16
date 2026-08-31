<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;
use Piwigo\Users\Projection\UserCountOption;

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
 * the one this template actually uses). `$colorscheme` is the ambient
 * `$themeconf['colorscheme']` the template's own `combineCss(id:
 * 'jquery.selectize', ...)` call reads.
 */
#[Template('user_list.latte')]
final readonly class UserListView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<array{id: int, name: string, counter: int}> $groupsForFilter
     * @param array<array-key, string> $themeOptions
     * @param array<string, string> $languageOptions
     * @param array<int, string> $associationOptions
     * @param array<string, string> $prefStatusOptions
     * @param array<string, UserCountOption> $nbUsersByStatus
     * @param array<int, string> $levelOptions
     * @param array<int, UserCountOption> $nbUsersByLevel
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
        public string $colorscheme,
    ) {}

    /**
     * `user_list.latte`'s own unconditional `{do combineScript(...)}`x7/
     * `{do combineCss(...)}`x5 (docs/PLAN.md's P42-B). The
     * `{capture $tmpFooterScript}...{/capture}{do footerScript(...)}`
     * block (100% static JS, zero Latte/PHP interpolation) is NOT
     * declared here as page data -- its content was moved directly
     * into `themes/admin/default/js/user_list.ts`, the same real asset
     * file the `user_list` script id below already registers.
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            // jQuery UI's own JS is gone (its slider widget, the only
            // real reason this page ever loaded it, ported off jQuery
            // in P49-B group 4) -- the CSS theme stays, since the
            // native port renders the identical `ui-slider`/
            // `ui-slider-handle`/... class structure it styles.
            AssetContribution::css('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/css/jquery-ui.css', id: 'jquery.ui'),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            // order: 10 is required, see issue 1080.
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::script('user_list', 'themes/admin/default/js/user_list.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/user_list.css', id: 'user_list'),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [
            'u_history' => $this->uHistory,
            'connected_user' => $this->connectedUser,
            'connected_user_status' => $this->connectedUserStatus,
            'owner' => $this->owner,
            'owner_username' => $this->ownerUsername,
            'groups_arr_name' => $this->groupsArrName,
            'groups_arr_id' => $this->groupsArrId,
            'guest_id' => $this->guestId,
            'csrf_token' => $this->csrfToken,
            'filter_group' => $this->filterGroup,
            'register_dates' => $this->registerDates,
            'view_selector' => $this->viewSelector,
            'pagination' => $this->pagination,
        ];
    }

    /**
     * `user_list.latte`'s own unconditional `{do exposeString(...)}`x18
     * (docs/PLAN.md's P42-B) -- `'Yes, I am sure'`/`'No, I have changed
     * my mind'` are dropped outright, not ported here: 2 of the 3
     * theme-base confirm-dialog strings `ThemeBaseAssets` already
     * registers unconditionally for every page.
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Are you sure you want to delete the user "%s"?',
            'and %s others',
            'You need to confirm deletion',
            'Please, enter a login',
            'Password is missing. Please enter the password.',
            'Password confirmation is missing. Please confirm the chosen password.',
            'Name field must not be empty',
            'The passwords do not match',
            'Please complete all fields',
            'Password copied',
            'Mail sent to %s [%s].',
            'Error sending email',
            "Cannot send an email to this user because he doesn't have an email address",
            'You are about to set %s as main user instead of %s, do you wish to continue ?',
            'To be sure, please rewrite the word “%s” below',
            'You can now change the main user from %s to %s.',
            '%s is the new main user',
            'Main user',
            'You are not authorised to change the main user, please ask your webmaster',
            'Set as main user',
            'This user must first be defined as the webmaster before it can be upgraded to the main user',
            'an error happened',
            'Copied link',
            'You cannot copy the password if the connection to this site is not secure.',
            'An activation link valid for %s has been sent to "%s". If the user doesn\'t receive the link, you can generate and copy a new one by editing the user and managing her password.',
            'Copy the link below and send it to the user so the password can be set.',
            'An activation link valid for %s was created but could not be sent. You can now copy the link below and send it to the user.',
            'Registered',
            'Last visit',
            'between %s and %s',
            'User %s added',
            '<b>%d</b> filtered users',
            '<b>%d</b> filtered user',
            'user_status_webmaster',
            'user_status_admin',
            'user_status_normal',
            'user_status_generic',
            'user_status_guest',
            '%d days',
            '%d photos',
            '%d photos per page',
            'Jan',
            'Feb',
            'Mar',
            'Apr',
            'May',
            'Jun',
            'Jul',
            'Aug',
            'Sep',
            'Oct',
            'Nov',
            'Dec',
        ];
    }
}
