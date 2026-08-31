<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Controller\AboutController;
use Piwigo\Controller\ActionController;
use Piwigo\Controller\Admin\AdminPopuphelpController;
use Piwigo\Controller\Api\ActivityListController;
use Piwigo\Controller\Api\CacheSizeController;
use Piwigo\Controller\Api\Categories\CategoryAvailableListController;
use Piwigo\Controller\Api\Categories\CategoryCreateController;
use Piwigo\Controller\Api\Categories\CategoryDeleteController;
use Piwigo\Controller\Api\Categories\CategoryDeleteRepresentativeController;
use Piwigo\Controller\Api\Categories\CategoryImagesController;
use Piwigo\Controller\Api\Categories\CategoryListController;
use Piwigo\Controller\Api\Categories\CategoryMoveController;
use Piwigo\Controller\Api\Categories\CategoryOrphanImpactController;
use Piwigo\Controller\Api\Categories\CategoryRefreshRepresentativeController;
use Piwigo\Controller\Api\Categories\CategoryReorderController;
use Piwigo\Controller\Api\Categories\CategorySetRepresentativeController;
use Piwigo\Controller\Api\Categories\CategoryUpdateController;
use Piwigo\Controller\Api\Comments\CommentDeleteController;
use Piwigo\Controller\Api\Comments\CommentListController;
use Piwigo\Controller\Api\Comments\CommentValidateController;
use Piwigo\Controller\Api\Extensions\CheckUpdatesController;
use Piwigo\Controller\Api\Extensions\ExtensionUpdateController;
use Piwigo\Controller\Api\Extensions\IgnoreUpdateController;
use Piwigo\Controller\Api\Extensions\PluginListController;
use Piwigo\Controller\Api\Extensions\PluginPerformActionController;
use Piwigo\Controller\Api\GeoIpLookupController;
use Piwigo\Controller\Api\Groups\GroupAddUserController;
use Piwigo\Controller\Api\Groups\GroupCreateController;
use Piwigo\Controller\Api\Groups\GroupDeleteController;
use Piwigo\Controller\Api\Groups\GroupDuplicateController;
use Piwigo\Controller\Api\Groups\GroupListController;
use Piwigo\Controller\Api\Groups\GroupMergeController;
use Piwigo\Controller\Api\Groups\GroupRemoveUserController;
use Piwigo\Controller\Api\Groups\GroupUpdateController;
use Piwigo\Controller\Api\History\HistoryLogController;
use Piwigo\Controller\Api\History\HistorySearchController;
use Piwigo\Controller\Api\Images\ImageAddCommentController;
use Piwigo\Controller\Api\Images\ImageCheckFileController;
use Piwigo\Controller\Api\Images\ImageDeleteController;
use Piwigo\Controller\Api\Images\ImageDeleteOrphansController;
use Piwigo\Controller\Api\Images\ImageFilteredSearchCreateController;
use Piwigo\Controller\Api\Images\ImageFormatDeleteController;
use Piwigo\Controller\Api\Images\ImageFormatSearchController;
use Piwigo\Controller\Api\Images\ImageGetController;
use Piwigo\Controller\Api\Images\ImageMissingDerivativesController;
use Piwigo\Controller\Api\Images\ImageRateController;
use Piwigo\Controller\Api\Images\ImageSearchController;
use Piwigo\Controller\Api\Images\ImageSetCategoryController;
use Piwigo\Controller\Api\Images\ImageSetMd5sumController;
use Piwigo\Controller\Api\Images\ImageSetPrivacyLevelController;
use Piwigo\Controller\Api\Images\ImageSetRankController;
use Piwigo\Controller\Api\Images\ImageSyncMetadataController;
use Piwigo\Controller\Api\Images\ImageUpdateController;
use Piwigo\Controller\Api\InfoController;
use Piwigo\Controller\Api\Session\ApiKeyCreateController;
use Piwigo\Controller\Api\Session\ApiKeyListController;
use Piwigo\Controller\Api\Session\ApiKeyRevokeController;
use Piwigo\Controller\Api\Session\ApiKeyUpdateController;
use Piwigo\Controller\Api\Session\CaddieAddController;
use Piwigo\Controller\Api\Session\FavoriteAddController;
use Piwigo\Controller\Api\Session\FavoriteListController;
use Piwigo\Controller\Api\Session\FavoriteRemoveController;
use Piwigo\Controller\Api\Session\MyInfoUpdateController;
use Piwigo\Controller\Api\Session\PreferenceSetController;
use Piwigo\Controller\Api\SessionController;
use Piwigo\Controller\Api\SessionLoginController;
use Piwigo\Controller\Api\SessionLogoutController;
use Piwigo\Controller\Api\Tags\TagAvailableListController;
use Piwigo\Controller\Api\Tags\TagCreateController;
use Piwigo\Controller\Api\Tags\TagDeleteController;
use Piwigo\Controller\Api\Tags\TagDuplicateController;
use Piwigo\Controller\Api\Tags\TagImagesController;
use Piwigo\Controller\Api\Tags\TagListController;
use Piwigo\Controller\Api\Tags\TagMergeController;
use Piwigo\Controller\Api\Tags\TagRenameController;
use Piwigo\Controller\Api\Uploads\TusUploadCreateController;
use Piwigo\Controller\Api\Uploads\TusUploadDeleteController;
use Piwigo\Controller\Api\Uploads\TusUploadHeadController;
use Piwigo\Controller\Api\Uploads\TusUploadOptionsController;
use Piwigo\Controller\Api\Uploads\TusUploadPatchController;
use Piwigo\Controller\Api\Uploads\UploadCompleteBatchController;
use Piwigo\Controller\Api\Uploads\UploadDuplicatesController;
use Piwigo\Controller\Api\Uploads\UploadReadinessController;
use Piwigo\Controller\Api\Users\UserCreateController;
use Piwigo\Controller\Api\Users\UserDeleteController;
use Piwigo\Controller\Api\Users\UserDeleteRatingsController;
use Piwigo\Controller\Api\Users\UserGeneratePasswordLinkController;
use Piwigo\Controller\Api\Users\UserGetAuthKeyController;
use Piwigo\Controller\Api\Users\UserListController;
use Piwigo\Controller\Api\Users\UserSetMainUserController;
use Piwigo\Controller\Api\Users\UserUpdateController;
use Piwigo\Controller\Api\VersionController;
use Piwigo\Controller\CommentsController;
use Piwigo\Controller\CustomLogoController;
use Piwigo\Controller\FeedController;
use Piwigo\Controller\GalleryController;
use Piwigo\Controller\IdentificationController;
use Piwigo\Controller\ImageDerivativeController;
use Piwigo\Controller\NbmController;
use Piwigo\Controller\NotificationController;
use Piwigo\Controller\PasswordController;
use Piwigo\Controller\PictureController;
use Piwigo\Controller\PopuphelpController;
use Piwigo\Controller\ProfileController;
use Piwigo\Controller\QSearchController;
use Piwigo\Controller\RegisterController;
use Piwigo\Controller\SearchController;
use Piwigo\Controller\TagsController;
use Piwigo\Controller\TestErrorsController;
use Piwigo\Controller\VitalsController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes match the literal root-file path (e.g. /about.php) -- these files
 * are real, individually-requested entry points (the web server invokes
 * them directly); clean-URL rewriting is a separate .htaccess/permalink
 * concern.
 * tests/Unit/Routing/RouterTest.php dispatches against an in-memory
 * RouteCollection built inline, not this class.
 */
final class RouteDefinitions
{
    public static function all(): RouteCollection
    {
        $routes = new RouteCollection();

        $routes->add('about', new Route('/about.php', [
            '_controller' => AboutController::class,
        ]));

        $routes->add('popuphelp', new Route('/popuphelp.php', [
            '_controller' => PopuphelpController::class,
        ]));

        $routes->add('admin_popuphelp', new Route('/admin/popuphelp.php', [
            '_controller' => AdminPopuphelpController::class,
        ]));

        $routes->add('nbm', new Route('/nbm.php', [
            '_controller' => NbmController::class,
        ]));

        $routes->add('notification', new Route('/notification.php', [
            '_controller' => NotificationController::class,
        ]));

        $routes->add('qsearch', new Route('/qsearch.php', [
            '_controller' => QSearchController::class,
        ]));

        $routes->add('tags', new Route('/tags.php', [
            '_controller' => TagsController::class,
        ]));

        $routes->add('identification', new Route('/identification.php', [
            '_controller' => IdentificationController::class,
        ]));

        $routes->add('password', new Route('/password.php', [
            '_controller' => PasswordController::class,
        ]));

        $routes->add('register', new Route('/register.php', [
            '_controller' => RegisterController::class,
        ]));

        $routes->add('profile', new Route('/profile.php', [
            '_controller' => ProfileController::class,
        ]));

        $routes->add('comments', new Route('/comments.php', [
            '_controller' => CommentsController::class,
        ]));

        $routes->add('search', new Route('/search.php', [
            '_controller' => SearchController::class,
        ]));

        $routes->add('feed', new Route('/feed.php', [
            '_controller' => FeedController::class,
        ]));

        $routes->add('logo', new Route('/logo.php', [
            '_controller' => CustomLogoController::class,
        ]));

        $routes->add('index', new Route('/index.php', [
            '_controller' => GalleryController::class,
        ]));

        // Apache's DirectoryIndex serves index.php's content for a bare directory
        // request (e.g. get_gallery_home_url()'s own fallback to the site root
        // when $conf['gallery_url'] isn't set and $conf['php_extension_in_urls']
        // is off) without rewriting REQUEST_URI to include "index.php" --
        // Router::pathInfo() strips the SCRIPT_NAME-derived mount-point prefix
        // from that bare request and is left with "/", which needs its own route
        // (without it, a real post-login redirect 404's here, caught by the
        // Browser test suite's login helper).
        $routes->add('index_directory_root', new Route('/', [
            '_controller' => GalleryController::class,
        ]));

        $routes->add('picture', new Route('/picture.php', [
            '_controller' => PictureController::class,
        ]));

        $routes->add('action', new Route('/action.php', [
            '_controller' => ActionController::class,
        ]));

        // Clean URL (no .php), rewritten to analytics_vitals.php by .htaccess --
        // Router::pathInfo() matches against this raw REQUEST_URI, not the
        // rewritten-to filename (see that file's own docblock).
        $routes->add('analytics_vitals', new Route('/analytics/vitals', [
            '_controller' => VitalsController::class,
        ]));

        // i.php supports 2 URL styles depending on Config::questionMarkInUrls() --
        // query-string ("/i.php?/upload/...", where the query string never
        // becomes part of the URI path Router matches against, leaving a bare
        // "/i.php") and PATH_INFO ("/i.php/upload/...", a real tail after the
        // script name). The wildcard `{tail}` (default '', requirement '.*' so it
        // matches slashes too) is the one route pattern in this file that isn't a
        // flat literal path, needed to match both shapes; ImageDerivativeController
        // itself still parses the real derivative request from
        // $_SERVER['PATH_INFO']/['QUERY_STRING'] directly, not from this route's
        // own {tail} capture -- see tests/Unit/Routing/RouterTest.php's own
        // dedicated coverage of this exact pattern.
        $routes->add('derivative_image', new Route('/i.php{tail}', [
            '_controller' => ImageDerivativeController::class,
            'tail' => '',
        ], [
            'tail' => '.*',
        ]));

        // Test-mode-only error drain (docs/PLAN.md finding #7) --
        // TestErrorsController itself 404s outside test mode; this route is safe
        // to register unconditionally.
        $routes->add('test_errors', new Route('/__test/errors', [
            '_controller' => TestErrorsController::class,
        ]));

        // Public API v1 -- one route per resource/method. No
        // catch-all here on purpose: a route that unconditionally matches
        // every method would shadow a real route's own 405 on a method
        // mismatch (symfony/routing only throws MethodNotAllowedException
        // when literally no route fully matches) -- an unmatched
        // /api/v1/... path is a genuine RouteMatchStatus::NotFound instead,
        // turned into RFC 9457 problem+json by Http\Middleware\
        // ApiErrorMiddleware, not by a route.
        $routes->add('api_v1_version', new Route('/api/v1/version', defaults: [
            '_controller' => VersionController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_session', new Route('/api/v1/session', defaults: [
            '_controller' => SessionController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_session_login', new Route('/api/v1/session', defaults: [
            '_controller' => SessionLoginController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_session_logout', new Route('/api/v1/session', defaults: [
            '_controller' => SessionLogoutController::class,
        ], methods: ['DELETE']));

        // Self-service "my account" sub-resources -- pwg.users.setMyInfo/
        // preferences.set/api_key.*/favorites.*, all scoped to the calling
        // session's own account.
        $routes->add('api_v1_session_update', new Route('/api/v1/session', defaults: [
            '_controller' => MyInfoUpdateController::class,
        ], methods: ['PATCH']));

        $routes->add('api_v1_session_preferences_set', new Route('/api/v1/session/preferences/{param}', defaults: [
            '_controller' => PreferenceSetController::class,
        ], requirements: [
            'param' => '[a-zA-Z0-9_-]+',
        ], methods: ['PUT']));

        $routes->add('api_v1_session_api_keys_list', new Route('/api/v1/session/api-keys', defaults: [
            '_controller' => ApiKeyListController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_session_api_keys_create', new Route('/api/v1/session/api-keys', defaults: [
            '_controller' => ApiKeyCreateController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_session_api_keys_update', new Route('/api/v1/session/api-keys/{pkid}', defaults: [
            '_controller' => ApiKeyUpdateController::class,
        ], requirements: [
            'pkid' => 'pkid-\d{8}-[a-zA-Z0-9]{20}',
        ], methods: ['PATCH']));

        $routes->add('api_v1_session_api_keys_revoke', new Route('/api/v1/session/api-keys/{pkid}', defaults: [
            '_controller' => ApiKeyRevokeController::class,
        ], requirements: [
            'pkid' => 'pkid-\d{8}-[a-zA-Z0-9]{20}',
        ], methods: ['DELETE']));

        $routes->add('api_v1_session_favorites_list', new Route('/api/v1/session/favorites', defaults: [
            '_controller' => FavoriteListController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_session_favorites_add', new Route('/api/v1/session/favorites/{imageId}', defaults: [
            '_controller' => FavoriteAddController::class,
        ], requirements: [
            'imageId' => '\d+',
        ], methods: ['PUT']));

        $routes->add('api_v1_session_favorites_remove', new Route('/api/v1/session/favorites/{imageId}', defaults: [
            '_controller' => FavoriteRemoveController::class,
        ], requirements: [
            'imageId' => '\d+',
        ], methods: ['DELETE']));

        // Admin-gated -- the caddie/lightbox is a Batch Manager feature,
        // not a general visitor one.
        $routes->add('api_v1_session_caddie_add', new Route('/api/v1/session/caddie', defaults: [
            '_controller' => CaddieAddController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_info', new Route('/api/v1/info', defaults: [
            '_controller' => InfoController::class,
        ], methods: ['GET']));

        // pwg.getCacheSize's real replacement -- admin only, the
        // Maintenance page's own "calculate cache size" button.
        $routes->add('api_v1_cache_size', new Route('/api/v1/cache-size', defaults: [
            '_controller' => CacheSizeController::class,
        ], methods: ['GET']));

        // Real replacement for jquery.geoip.js's own dead-endpoint JSONP
        // call -- admin only, history.ts's/rating_user.ts's IP-hover
        // tooltip.
        $routes->add('api_v1_geoip', new Route('/api/v1/geoip', defaults: [
            '_controller' => GeoIpLookupController::class,
        ], methods: ['GET']));

        // Tags resource family -- admin-consumed (pwg.tags.getAdminList's
        // own audience, permissions not taken into account).
        $routes->add('api_v1_tags_list', new Route('/api/v1/tags', defaults: [
            '_controller' => TagListController::class,
        ], methods: ['GET']));

        // Public, permission-filtered browsing shape (pwg.tags.getList/
        // getImages) -- a genuinely different query (TagService::
        // getAvailableTags(), not getAllTags()), kept as its own resource
        // rather than folded into GET /api/v1/tags behind a permission
        // check.

        $routes->add('api_v1_tags_available', new Route('/api/v1/tags/available', defaults: [
            '_controller' => TagAvailableListController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_tags_images', new Route('/api/v1/tags/images', defaults: [
            '_controller' => TagImagesController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_tags_create', new Route('/api/v1/tags', defaults: [
            '_controller' => TagCreateController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_tags_rename', new Route('/api/v1/tags/{id}', defaults: [
            '_controller' => TagRenameController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['PATCH']));

        $routes->add('api_v1_tags_delete', new Route('/api/v1/tags/{id}', defaults: [
            '_controller' => TagDeleteController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['DELETE']));

        $routes->add('api_v1_tags_duplicate', new Route('/api/v1/tags/{id}/actions/duplicate', defaults: [
            '_controller' => TagDuplicateController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['POST']));

        $routes->add('api_v1_tags_merge', new Route('/api/v1/tags/actions/merge', defaults: [
            '_controller' => TagMergeController::class,
        ], methods: ['POST']));

        // Groups resource family -- admin-consumed, mirroring the Tags
        // family's own shape.
        $routes->add('api_v1_groups_list', new Route('/api/v1/groups', defaults: [
            '_controller' => GroupListController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_groups_create', new Route('/api/v1/groups', defaults: [
            '_controller' => GroupCreateController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_groups_update', new Route('/api/v1/groups/{id}', defaults: [
            '_controller' => GroupUpdateController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['PATCH']));

        $routes->add('api_v1_groups_delete', new Route('/api/v1/groups/{id}', defaults: [
            '_controller' => GroupDeleteController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['DELETE']));

        $routes->add('api_v1_groups_duplicate', new Route('/api/v1/groups/{id}/actions/duplicate', defaults: [
            '_controller' => GroupDuplicateController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['POST']));

        $routes->add('api_v1_groups_add_user', new Route('/api/v1/groups/{id}/actions/add-user', defaults: [
            '_controller' => GroupAddUserController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['POST']));

        $routes->add('api_v1_groups_remove_user', new Route('/api/v1/groups/{id}/actions/remove-user', defaults: [
            '_controller' => GroupRemoveUserController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['POST']));

        $routes->add('api_v1_groups_merge', new Route('/api/v1/groups/actions/merge', defaults: [
            '_controller' => GroupMergeController::class,
        ], methods: ['POST']));

        // Categories (albums) resource family -- admin-consumed.
        // pwg.categories.setRepresentative wasn't in the plan's own
        // admin-consumed table (an oversight there) but is clearly the
        // same audience as deleteRepresentative/refreshRepresentative,
        // so it's folded into this family too.
        $routes->add('api_v1_categories_list', new Route('/api/v1/categories', defaults: [
            '_controller' => CategoryListController::class,
        ], methods: ['GET']));

        // Public, permission-filtered browsing shape (pwg.categories.
        // getList/getImages) -- kept as its own resource, same reasoning
        // as the Tags family's own available/images split.
        $routes->add('api_v1_categories_available', new Route('/api/v1/categories/available', defaults: [
            '_controller' => CategoryAvailableListController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_categories_images', new Route('/api/v1/categories/images', defaults: [
            '_controller' => CategoryImagesController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_categories_create', new Route('/api/v1/categories', defaults: [
            '_controller' => CategoryCreateController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_categories_update', new Route('/api/v1/categories/{id}', defaults: [
            '_controller' => CategoryUpdateController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['PATCH']));

        $routes->add('api_v1_categories_delete', new Route('/api/v1/categories/{id}', defaults: [
            '_controller' => CategoryDeleteController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['DELETE']));

        $routes->add('api_v1_categories_move', new Route('/api/v1/categories/actions/move', defaults: [
            '_controller' => CategoryMoveController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_categories_reorder', new Route('/api/v1/categories/actions/reorder', defaults: [
            '_controller' => CategoryReorderController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_categories_set_representative', new Route('/api/v1/categories/{id}/representative', defaults: [
            '_controller' => CategorySetRepresentativeController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['PUT']));

        $routes->add('api_v1_categories_delete_representative', new Route('/api/v1/categories/{id}/representative', defaults: [
            '_controller' => CategoryDeleteRepresentativeController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['DELETE']));

        $routes->add('api_v1_categories_refresh_representative', new Route('/api/v1/categories/{id}/actions/refresh-representative', defaults: [
            '_controller' => CategoryRefreshRepresentativeController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['POST']));

        // pwg.categories.calculateOrphans's real replacement -- the
        // "delete album" confirmation dialog's own data source.
        $routes->add('api_v1_categories_orphan_impact', new Route('/api/v1/categories/{id}/orphan-impact', defaults: [
            '_controller' => CategoryOrphanImpactController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['GET']));

        // Users resource family -- admin-consumed. The self-service
        // preferences-set action on the calling user's own account isn't
        // included here -- not an admin-management action, deferred to
        // the same later pass as setMyInfo/favorites/getAuthKey/api_key.*.
        $routes->add('api_v1_users_list', new Route('/api/v1/users', defaults: [
            '_controller' => UserListController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_users_create', new Route('/api/v1/users', defaults: [
            '_controller' => UserCreateController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_users_update', new Route('/api/v1/users/{id}', defaults: [
            '_controller' => UserUpdateController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['PATCH']));

        $routes->add('api_v1_users_delete', new Route('/api/v1/users/{id}', defaults: [
            '_controller' => UserDeleteController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['DELETE']));

        $routes->add('api_v1_users_generate_password_link', new Route('/api/v1/users/{id}/actions/generate-password-link', defaults: [
            '_controller' => UserGeneratePasswordLinkController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['POST']));

        $routes->add('api_v1_users_set_main_user', new Route('/api/v1/users/{id}/actions/set-main-user', defaults: [
            '_controller' => UserSetMainUserController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['POST']));

        // Admin-gated -- see UserGetAuthKeyController's own docblock.
        $routes->add('api_v1_users_get_auth_key', new Route('/api/v1/users/{id}/actions/get-auth-key', defaults: [
            '_controller' => UserGetAuthKeyController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['POST']));

        // Admin-gated.
        $routes->add('api_v1_users_delete_ratings', new Route('/api/v1/users/{id}/actions/delete-ratings', defaults: [
            '_controller' => UserDeleteRatingsController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['POST']));

        // Comments (userComments) resource family -- admin moderation view.
        // Bulk actions, matching the admin moderation UI's batch-select
        // workflow -- no per-comment resource verbs.
        $routes->add('api_v1_comments_list', new Route('/api/v1/comments', defaults: [
            '_controller' => CommentListController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_comments_delete', new Route('/api/v1/comments/actions/delete', defaults: [
            '_controller' => CommentDeleteController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_comments_validate', new Route('/api/v1/comments/actions/validate', defaults: [
            '_controller' => CommentValidateController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_activity_list', new Route('/api/v1/activity', defaults: [
            '_controller' => ActivityListController::class,
        ], methods: ['GET']));

        // Public, no auth gate at all; every gallery-page-view fires
        // this, anonymous visitors included.
        $routes->add('api_v1_history_log', new Route('/api/v1/history/log', defaults: [
            '_controller' => HistoryLogController::class,
        ], methods: ['POST']));

        // Admin-gated read, no CSRF needed.
        $routes->add('api_v1_history_search', new Route('/api/v1/history/search', defaults: [
            '_controller' => HistorySearchController::class,
        ], methods: ['GET']));

        // Plugins/extensions resource families -- webmaster-gated.
        // pwg.themes.performAction has no first-party caller anywhere in
        // this codebase (verified, per the plan's own method
        // classification) -- no REST conversion needed.
        $routes->add('api_v1_plugins_list', new Route('/api/v1/plugins', defaults: [
            '_controller' => PluginListController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_plugins_perform_action', new Route('/api/v1/plugins/{id}/actions/perform', defaults: [
            '_controller' => PluginPerformActionController::class,
        ], requirements: [
            'id' => '[a-zA-Z0-9_-]+',
        ], methods: ['POST']));

        $routes->add('api_v1_extensions_check_updates', new Route('/api/v1/extensions/updates', defaults: [
            '_controller' => CheckUpdatesController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_extensions_ignore_update', new Route('/api/v1/extensions/updates/ignore', defaults: [
            '_controller' => IgnoreUpdateController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_extensions_update', new Route('/api/v1/extensions/{type}/{id}/actions/update', defaults: [
            '_controller' => ExtensionUpdateController::class,
        ], requirements: [
            'type' => 'plugins|themes|languages',
            'id' => '[a-zA-Z0-9_-]+',
        ], methods: ['POST']));

        // Images resource family -- admin-consumed. getInfo is public/
        // permission-filtered (no AdminGuard); filteredSearch.create is
        // public too (the front-end's own
        // advanced-search page calls it, not just admin). The upload-flow
        // methods (add/addChunk/addFile/addSimple/checkFiles/checkUpload/
        // exist/upload/uploadAsync/uploadCompleted) fold into tus later;
        // rate/search/setCategory/setPrivacyLevel/setRank/addComment are
        // external-only, a separate later pass.
        $routes->add('api_v1_images_get', new Route('/api/v1/images/{id}', defaults: [
            '_controller' => ImageGetController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['GET']));

        $routes->add('api_v1_images_update', new Route('/api/v1/images/{id}', defaults: [
            '_controller' => ImageUpdateController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['PATCH']));

        $routes->add('api_v1_images_delete', new Route('/api/v1/images/actions/delete', defaults: [
            '_controller' => ImageDeleteController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_images_formats_delete', new Route('/api/v1/images/formats/actions/delete', defaults: [
            '_controller' => ImageFormatDeleteController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_images_formats_search', new Route('/api/v1/images/formats/actions/search', defaults: [
            '_controller' => ImageFormatSearchController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_images_filtered_search_create', new Route('/api/v1/images/searches', defaults: [
            '_controller' => ImageFilteredSearchCreateController::class,
        ], methods: ['POST']));

        // External-only images methods -- addComment/rate/search are
        // public; setCategory/setPrivacyLevel/setRank are admin-gated.
        $routes->add('api_v1_images_add_comment', new Route('/api/v1/images/{id}/comments', defaults: [
            '_controller' => ImageAddCommentController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['POST']));

        $routes->add('api_v1_images_rate', new Route('/api/v1/images/{id}/rating', defaults: [
            '_controller' => ImageRateController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['PUT']));

        $routes->add('api_v1_images_search', new Route('/api/v1/images/search', defaults: [
            '_controller' => ImageSearchController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_images_set_category', new Route('/api/v1/images/actions/set-category', defaults: [
            '_controller' => ImageSetCategoryController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_images_set_privacy_level', new Route('/api/v1/images/actions/set-privacy-level', defaults: [
            '_controller' => ImageSetPrivacyLevelController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_images_set_rank', new Route('/api/v1/images/actions/set-rank', defaults: [
            '_controller' => ImageSetRankController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_images_check_file', new Route('/api/v1/images/{id}/actions/check-file', defaults: [
            '_controller' => ImageCheckFileController::class,
        ], requirements: [
            'id' => '\d+',
        ], methods: ['GET']));

        // pwg.images.syncMetadata/deleteOrphans's real replacements -- the
        // Batch Manager "unit"/"global" panels' own actions.
        $routes->add('api_v1_images_sync_metadata', new Route('/api/v1/images/actions/sync-metadata', defaults: [
            '_controller' => ImageSyncMetadataController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_images_delete_orphans', new Route('/api/v1/images/actions/delete-orphans', defaults: [
            '_controller' => ImageDeleteOrphansController::class,
        ], methods: ['POST']));

        // pwg.getMissingDerivatives/pwg.images.setMd5sum's real
        // replacements -- missed from the original admin-consumed tier
        // pass, found and built while converting batchManagerGlobal.js's
        // own real callers of both.
        $routes->add('api_v1_images_missing_derivatives', new Route('/api/v1/images/actions/missing-derivatives', defaults: [
            '_controller' => ImageMissingDerivativesController::class,
        ], methods: ['POST']));

        $routes->add('api_v1_images_set_md5sum', new Route('/api/v1/images/actions/set-md5sum', defaults: [
            '_controller' => ImageSetMd5sumController::class,
        ], methods: ['POST']));

        // Uploads -- admin-only readiness/duplicate-detection helpers
        // (pwg.images.checkUpload/exist's replacements). The actual
        // byte-transfer surface (addChunk/add/addFile/addSimple/upload/
        // uploadAsync) folds into a tus-protocol endpoint, and
        // uploadCompleted into a batch-finish action -- both separate,
        // later sub-items.
        $routes->add('api_v1_uploads_readiness', new Route('/api/v1/uploads/readiness', defaults: [
            '_controller' => UploadReadinessController::class,
        ], methods: ['GET']));

        $routes->add('api_v1_uploads_duplicates', new Route('/api/v1/uploads/duplicates', defaults: [
            '_controller' => UploadDuplicatesController::class,
        ], methods: ['GET']));

        // tus 1.0.0 resumable-upload protocol -- pwg.images.{addChunk,add,
        // addFile,addSimple,upload,uploadAsync}'s real replacement. Every
        // predecessor is requiresAuth: true (admin-only); every mutating
        // verb here gets CSRF too, even though none of those predecessors
        // checked it. OPTIONS is deliberately the one ungated route in
        // this family -- see TusUploadOptionsController's own docblock.
        $routes->add('api_v1_uploads_options', new Route('/api/v1/uploads', defaults: [
            '_controller' => TusUploadOptionsController::class,
            '_bypass_idempotency' => true,
        ], methods: ['OPTIONS']));

        $routes->add('api_v1_uploads_create', new Route('/api/v1/uploads', defaults: [
            '_controller' => TusUploadCreateController::class,
            '_bypass_idempotency' => true,
        ], methods: ['POST']));

        $routes->add('api_v1_uploads_head', new Route('/api/v1/uploads/{id}', defaults: [
            '_controller' => TusUploadHeadController::class,
            '_bypass_idempotency' => true,
        ], requirements: [
            'id' => '[a-f0-9]{32}',
        ], methods: ['HEAD']));

        $routes->add('api_v1_uploads_patch', new Route('/api/v1/uploads/{id}', defaults: [
            '_controller' => TusUploadPatchController::class,
            '_bypass_idempotency' => true,
        ], requirements: [
            'id' => '[a-f0-9]{32}',
        ], methods: ['PATCH']));

        $routes->add('api_v1_uploads_delete', new Route('/api/v1/uploads/{id}', defaults: [
            '_controller' => TusUploadDeleteController::class,
            '_bypass_idempotency' => true,
        ], requirements: [
            'id' => '[a-f0-9]{32}',
        ], methods: ['DELETE']));

        $routes->add('api_v1_uploads_complete_batch', new Route('/api/v1/uploads/actions/complete-batch', defaults: [
            '_controller' => UploadCompleteBatchController::class,
        ], methods: ['POST']));

        return $routes;
    }
}
