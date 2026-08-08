<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\AdminShell::runDispatch()} before dispatching to
 * the page-slug sub-controller. `$uUpdates`, `$uComments`, and
 * `$nbPendingComments` are genuinely optional -- the original code only
 * ever assigns those 3 template keys under their own runtime condition,
 * omitted here (not present as a null value) to match that exact
 * original behavior.
 */
final readonly class AdminShellFramePageContext implements TemplatePageContext
{
    /**
     * @param array<int, string> $whatsNewImgs
     */
    public function __construct(
        public ?string $username,
        public bool $enableSynchronization,
        public string $uSiteManager,
        public string $uHistoryStat,
        public string $uFaq,
        public string $uMaintenance,
        public string $uNotificationByMail,
        public string $uConfigGeneral,
        public string $uConfigDisplay,
        public string $uConfigExtents,
        public string $uConfigMenubar,
        public string $uConfigLanguages,
        public string $uConfigThemes,
        public string $uCategories,
        public string $uAlbums,
        public string $uCatOptions,
        public string $uCatUpdate,
        public string $uRating,
        public string $uRecentSet,
        public string $uBatch,
        public string $uTags,
        public string $uUsers,
        public string $uGroups,
        public string $uReturn,
        public string $uAdmin,
        public string $uLogout,
        public string $uPlugins,
        public string $uAddPhotos,
        public string $uChangeTheme,
        public string $adminPageTitle,
        public string $adminPageObjectId,
        public bool $uShowTemplateTab,
        public bool $showRating,
        public ?string $uUpdates,
        public ?string $uComments,
        public ?int $nbPendingComments,
        public int $nbPhotosInCaddie,
        public string $uCaddie,
        public int $nbOrphans,
        public string $uOrphans,
        public bool $showWhatsNew,
        public string $whatsNewMajorVersion,
        public string $releaseNoteUrl,
        public array $whatsNewImgs,
        public bool $displayBell,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'USERNAME' => $this->username,
            'ENABLE_SYNCHRONIZATION' => $this->enableSynchronization,
            'U_SITE_MANAGER' => $this->uSiteManager,
            'U_HISTORY_STAT' => $this->uHistoryStat,
            'U_FAQ' => $this->uFaq,
            'U_MAINTENANCE' => $this->uMaintenance,
            'U_NOTIFICATION_BY_MAIL' => $this->uNotificationByMail,
            'U_CONFIG_GENERAL' => $this->uConfigGeneral,
            'U_CONFIG_DISPLAY' => $this->uConfigDisplay,
            'U_CONFIG_EXTENTS' => $this->uConfigExtents,
            'U_CONFIG_MENUBAR' => $this->uConfigMenubar,
            'U_CONFIG_LANGUAGES' => $this->uConfigLanguages,
            'U_CONFIG_THEMES' => $this->uConfigThemes,
            'U_CATEGORIES' => $this->uCategories,
            'U_ALBUMS' => $this->uAlbums,
            'U_CAT_OPTIONS' => $this->uCatOptions,
            'U_CAT_UPDATE' => $this->uCatUpdate,
            'U_RATING' => $this->uRating,
            'U_RECENT_SET' => $this->uRecentSet,
            'U_BATCH' => $this->uBatch,
            'U_TAGS' => $this->uTags,
            'U_USERS' => $this->uUsers,
            'U_GROUPS' => $this->uGroups,
            'U_RETURN' => $this->uReturn,
            'U_ADMIN' => $this->uAdmin,
            'U_LOGOUT' => $this->uLogout,
            'U_PLUGINS' => $this->uPlugins,
            'U_ADD_PHOTOS' => $this->uAddPhotos,
            'U_CHANGE_THEME' => $this->uChangeTheme,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'ADMIN_PAGE_OBJECT_ID' => $this->adminPageObjectId,
            'U_SHOW_TEMPLATE_TAB' => $this->uShowTemplateTab,
            'SHOW_RATING' => $this->showRating,
            'NB_PHOTOS_IN_CADDIE' => $this->nbPhotosInCaddie,
            'U_CADDIE' => $this->uCaddie,
            'NB_ORPHANS' => $this->nbOrphans,
            'U_ORPHANS' => $this->uOrphans,
            'SHOW_WHATS_NEW' => $this->showWhatsNew,
            'WHATS_NEW_MAJOR_VERSION' => $this->whatsNewMajorVersion,
            'RELEASE_NOTE_URL' => $this->releaseNoteUrl,
            'WHATS_NEW_IMGS' => $this->whatsNewImgs,
            'DISPLAY_BELL' => $this->displayBell,
        ];

        if ($this->uUpdates !== null) {
            $result['U_UPDATES'] = $this->uUpdates;
        }

        if ($this->uComments !== null) {
            $result['U_COMMENTS'] = $this->uComments;
        }

        if ($this->nbPendingComments !== null) {
            $result['NB_PENDING_COMMENTS'] = $this->nbPendingComments;
        }

        return $result;
    }
}
