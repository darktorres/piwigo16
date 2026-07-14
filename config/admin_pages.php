<?php

declare(strict_types=1);

// Maps an admin.php `?page=` slug to the AdminSubControllerInterface class
// that now handles it. Slugs absent from this map still fall back to the
// legacy `include admin/<slug>.php` (Piwigo\Bootstrap\AdminDispatcher) --
// P21 migrates this map's entries batch by batch; a slug only leaves the
// legacy path once its sub-controller is built, tested, and live-verified.
// P23 deletes the fallback once every real slug has an entry here.

/** @var array<string, class-string<\Piwigo\Controller\Admin\AdminSubControllerInterface>> */
return [
    'photos_add' => \Piwigo\Controller\Admin\PhotosAddSubController::class,
    'album' => \Piwigo\Controller\Admin\AlbumSubController::class,
    'albums' => \Piwigo\Controller\Admin\AlbumsSubController::class,
    'cat_list' => \Piwigo\Controller\Admin\CatListSubController::class,
    'cat_options' => \Piwigo\Controller\Admin\CatOptionsSubController::class,
    'group_list' => \Piwigo\Controller\Admin\GroupListSubController::class,
    'group_perm' => \Piwigo\Controller\Admin\GroupPermSubController::class,
    'user_list' => \Piwigo\Controller\Admin\UserListSubController::class,
    'user_perm' => \Piwigo\Controller\Admin\UserPermSubController::class,
    'user_activity' => \Piwigo\Controller\Admin\UserActivitySubController::class,
    'configuration' => \Piwigo\Controller\Admin\ConfigurationSubController::class,
    'extend_for_templates' => \Piwigo\Controller\Admin\ExtendForTemplatesSubController::class,
    'menubar' => \Piwigo\Controller\Admin\MenubarSubController::class,
    'permalinks' => \Piwigo\Controller\Admin\PermalinksSubController::class,
    'picture_formats' => \Piwigo\Controller\Admin\PictureFormatsSubController::class,
    'picture_coi' => \Piwigo\Controller\Admin\PictureCoiSubController::class,
    'site_manager' => \Piwigo\Controller\Admin\SiteManagerSubController::class,
    'site_update' => \Piwigo\Controller\Admin\SiteUpdateSubController::class,
    'themes_standard_pages' => \Piwigo\Controller\Admin\ThemesStandardPagesSubController::class,
    'plugin' => \Piwigo\Controller\Admin\PluginSubController::class,
    'plugins' => \Piwigo\Controller\Admin\PluginsSubController::class,
    'theme' => \Piwigo\Controller\Admin\ThemeSubController::class,
    'themes' => \Piwigo\Controller\Admin\ThemesSubController::class,
    'languages' => \Piwigo\Controller\Admin\LanguagesSubController::class,
    'updates' => \Piwigo\Controller\Admin\UpdatesSubController::class,
    'batch_manager' => \Piwigo\Controller\Admin\BatchManagerSubController::class,
    'history' => \Piwigo\Controller\Admin\HistorySubController::class,
    'maintenance' => \Piwigo\Controller\Admin\MaintenanceSubController::class,
    'intro' => \Piwigo\Controller\Admin\IntroSubController::class,
    'comments' => \Piwigo\Controller\Admin\CommentsSubController::class,
    'help' => \Piwigo\Controller\Admin\HelpSubController::class,
    'rating' => \Piwigo\Controller\Admin\RatingSubController::class,
    'rating_user' => \Piwigo\Controller\Admin\RatingUserSubController::class,
    'tags' => \Piwigo\Controller\Admin\TagsSubController::class,
    'photo' => \Piwigo\Controller\Admin\PhotoSubController::class,
    'stats' => \Piwigo\Controller\Admin\StatsSubController::class,
    'notification_by_mail' => \Piwigo\Controller\Admin\NotificationByMailSubController::class,
];
