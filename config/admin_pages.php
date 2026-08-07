<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\AdminSubControllerInterface;
use Piwigo\Controller\Admin\AlbumsSubController;
use Piwigo\Controller\Admin\AlbumSubController;
use Piwigo\Controller\Admin\BatchManagerSubController;
use Piwigo\Controller\Admin\CatListSubController;
use Piwigo\Controller\Admin\CatOptionsSubController;
use Piwigo\Controller\Admin\CommentsSubController;
use Piwigo\Controller\Admin\ConfigurationSubController;
use Piwigo\Controller\Admin\ExtendForTemplatesSubController;
use Piwigo\Controller\Admin\GroupListSubController;
use Piwigo\Controller\Admin\GroupPermSubController;
use Piwigo\Controller\Admin\HelpSubController;
use Piwigo\Controller\Admin\HistorySubController;
use Piwigo\Controller\Admin\IntroSubController;
use Piwigo\Controller\Admin\LanguagesSubController;
use Piwigo\Controller\Admin\MaintenanceSubController;
use Piwigo\Controller\Admin\MenubarSubController;
use Piwigo\Controller\Admin\NotificationByMailSubController;
use Piwigo\Controller\Admin\PermalinksSubController;
use Piwigo\Controller\Admin\PhotosAddSubController;
use Piwigo\Controller\Admin\PhotoSubController;
use Piwigo\Controller\Admin\PictureCoiSubController;
use Piwigo\Controller\Admin\PictureFormatsSubController;
use Piwigo\Controller\Admin\PluginsSubController;
use Piwigo\Controller\Admin\PluginSubController;
use Piwigo\Controller\Admin\RatingSubController;
use Piwigo\Controller\Admin\RatingUserSubController;
use Piwigo\Controller\Admin\SiteManagerSubController;
use Piwigo\Controller\Admin\SiteUpdateSubController;
use Piwigo\Controller\Admin\StatsSubController;
use Piwigo\Controller\Admin\TagsSubController;
use Piwigo\Controller\Admin\ThemesStandardPagesSubController;
use Piwigo\Controller\Admin\ThemesSubController;
use Piwigo\Controller\Admin\ThemeSubController;
use Piwigo\Controller\Admin\UpdatesSubController;
use Piwigo\Controller\Admin\UserActivitySubController;
use Piwigo\Controller\Admin\UserListSubController;
use Piwigo\Controller\Admin\UserPermSubController;

// Maps an admin.php `?page=` slug to the AdminSubControllerInterface class
// that handles it. This map is the complete admin page registry: admin.php
// falls back to 'intro' for any slug not listed here, and
// Piwigo\Bootstrap\AdminDispatcher treats an unmapped slug reaching it as
// a programming error.
/** @var array<string, class-string<AdminSubControllerInterface>> */
return [
    'photos_add' => PhotosAddSubController::class,
    'album' => AlbumSubController::class,
    'albums' => AlbumsSubController::class,
    'cat_list' => CatListSubController::class,
    'cat_options' => CatOptionsSubController::class,
    'group_list' => GroupListSubController::class,
    'group_perm' => GroupPermSubController::class,
    'user_list' => UserListSubController::class,
    'user_perm' => UserPermSubController::class,
    'user_activity' => UserActivitySubController::class,
    'configuration' => ConfigurationSubController::class,
    'extend_for_templates' => ExtendForTemplatesSubController::class,
    'menubar' => MenubarSubController::class,
    'permalinks' => PermalinksSubController::class,
    'picture_formats' => PictureFormatsSubController::class,
    'picture_coi' => PictureCoiSubController::class,
    'site_manager' => SiteManagerSubController::class,
    'site_update' => SiteUpdateSubController::class,
    'themes_standard_pages' => ThemesStandardPagesSubController::class,
    'plugin' => PluginSubController::class,
    'plugins' => PluginsSubController::class,
    'theme' => ThemeSubController::class,
    'themes' => ThemesSubController::class,
    'languages' => LanguagesSubController::class,
    'updates' => UpdatesSubController::class,
    'batch_manager' => BatchManagerSubController::class,
    'history' => HistorySubController::class,
    'maintenance' => MaintenanceSubController::class,
    'intro' => IntroSubController::class,
    'comments' => CommentsSubController::class,
    'help' => HelpSubController::class,
    'rating' => RatingSubController::class,
    'rating_user' => RatingUserSubController::class,
    'tags' => TagsSubController::class,
    'photo' => PhotoSubController::class,
    'stats' => StatsSubController::class,
    'notification_by_mail' => NotificationByMailSubController::class,
];
