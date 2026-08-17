<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Controller\AboutController;
use Piwigo\Controller\ActionController;
use Piwigo\Controller\Admin\AdminPopuphelpController;
use Piwigo\Controller\Api\InfoController;
use Piwigo\Controller\Api\SessionController;
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
use Piwigo\Controller\WsController;
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

        $routes->add('ws', new Route('/ws.php', [
            '_controller' => WsController::class,
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

        // Public API v1 (P27) -- one route per resource/method, mirroring
        // WS's own pwg.* methods but as proper REST paths/verbs. No
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

        $routes->add('api_v1_info', new Route('/api/v1/info', defaults: [
            '_controller' => InfoController::class,
        ], methods: ['GET']));

        return $routes;
    }
}
