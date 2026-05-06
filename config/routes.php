<?php

declare(strict_types=1);

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Application route table.
 *
 * Each route name is also the key used by Router::generate() / UrlGenerator.
 * Controller FQCNs are referenced as strings so this file loads cleanly even
 * before Wave-A/B controllers are implemented.
 *
 * Sub-token parsing (pagination, chronology, slug) is NOT done here — {rest}
 * is a catch-all passed to the controller / SectionInitializer for further
 * parsing.  The router only identifies the section.
 */
$routes = new RouteCollection();

$get = ['GET'];
$gp  = ['GET', 'POST'];

// ── Gallery / browse ─────────────────────────────────────────────────────────

$routes->add('gallery', new Route(
    path:     '/',
    defaults: ['_controller' => 'Piwigo\Controller\GalleryController'],
    methods:  $get,
));

// /category/12-foo/start-24   → rest = "12-foo/start-24"
$routes->add('gallery_cat', new Route(
    path:         '/category/{rest}',
    defaults:     ['_controller' => 'Piwigo\Controller\GalleryController'],
    requirements: ['rest' => '.+'],
    methods:      $get,
));

// /picture/12-filename/category/7   → rest = "12-filename/category/7"
$routes->add('gallery_pic', new Route(
    path:         '/picture/{rest}',
    defaults:     ['_controller' => 'Piwigo\Controller\PictureController'],
    requirements: ['rest' => '.+'],
    methods:      $get,
));

// Bare tags cloud/alphabetic page (former tags.php)
$routes->add('tags', new Route(
    path:     '/tags',
    defaults: ['_controller' => 'Piwigo\Controller\TagsController'],
    methods:  $get,
));

// /tags/1+2+3/start-12   → rest = "1+2+3/start-12"
$routes->add('gallery_tags', new Route(
    path:         '/tags/{rest}',
    defaults:     ['_controller' => 'Piwigo\Controller\TagsController'],
    requirements: ['rest' => '.+'],
    methods:      $get,
));

// Bare search form submission (former search.php)
$routes->add('search', new Route(
    path:     '/search',
    defaults: ['_controller' => 'Piwigo\Controller\SearchController'],
    methods:  $gp,
));

// /search/42  or  /search/psk-HASH   → display search results (GalleryController)
$routes->add('gallery_search', new Route(
    path:         '/search/{id}',
    defaults:     ['_controller' => 'Piwigo\Controller\GalleryController', 'rest' => ''],
    requirements: ['id' => '[a-zA-Z0-9\-]+'],
    methods:      $gp,
));

// /search/42/start-12  or  /search/psk-HASH/start-12   → id + pagination rest
$routes->add('gallery_search_paged', new Route(
    path:         '/search/{id}/{rest}',
    defaults:     ['_controller' => 'Piwigo\Controller\GalleryController'],
    requirements: ['id' => '[a-zA-Z0-9\-]+', 'rest' => '.+'],
    methods:      $gp,
));

$routes->add('favorites', new Route(
    path:     '/favorites',
    defaults: ['_controller' => 'Piwigo\Controller\GalleryController', 'section' => 'favorites'],
    methods:  $get,
));

$routes->add('recent_pics', new Route(
    path:     '/recent',
    defaults: ['_controller' => 'Piwigo\Controller\GalleryController', 'section' => 'recent_pics'],
    methods:  $get,
));

$routes->add('best_rated', new Route(
    path:     '/best-rated',
    defaults: ['_controller' => 'Piwigo\Controller\GalleryController', 'section' => 'best_rated'],
    methods:  $get,
));

$routes->add('most_visited', new Route(
    path:     '/most-visited',
    defaults: ['_controller' => 'Piwigo\Controller\GalleryController', 'section' => 'most_visited'],
    methods:  $get,
));

$routes->add('recent_cats', new Route(
    path:     '/recent-albums',
    defaults: ['_controller' => 'Piwigo\Controller\GalleryController', 'section' => 'recent_cats'],
    methods:  $get,
));

$routes->add('random', new Route(
    path:     '/random',
    defaults: ['_controller' => 'Piwigo\Controller\GalleryController', 'section' => 'list'],
    methods:  $get,
));

// ── Auth / user ───────────────────────────────────────────────────────────────

$routes->add('identification', new Route(
    path:     '/identification',
    defaults: ['_controller' => 'Piwigo\Controller\IdentificationController'],
    methods:  $gp,
));

$routes->add('register', new Route(
    path:     '/register',
    defaults: ['_controller' => 'Piwigo\Controller\RegisterController'],
    methods:  $gp,
));

$routes->add('password', new Route(
    path:     '/password',
    defaults: ['_controller' => 'Piwigo\Controller\PasswordController'],
    methods:  $gp,
));

$routes->add('profile', new Route(
    path:     '/profile',
    defaults: ['_controller' => 'Piwigo\Controller\ProfileController'],
    methods:  $gp,
));

// ── Content ───────────────────────────────────────────────────────────────────

$routes->add('comments', new Route(
    path:     '/comments',
    defaults: ['_controller' => 'Piwigo\Controller\CommentsController'],
    methods:  $gp,
));

$routes->add('notification', new Route(
    path:     '/notification',
    defaults: ['_controller' => 'Piwigo\Controller\NotificationController'],
    methods:  $get,
));

$routes->add('feed', new Route(
    path:     '/feed',
    defaults: ['_controller' => 'Piwigo\Controller\FeedController'],
    methods:  $get,
));

$routes->add('about', new Route(
    path:     '/about',
    defaults: ['_controller' => 'Piwigo\Controller\AboutController'],
    methods:  $get,
));

$routes->add('nbm', new Route(
    path:     '/nbm',
    defaults: ['_controller' => 'Piwigo\Controller\NbmController'],
    methods:  $gp,
));

$routes->add('popuphelp', new Route(
    path:     '/popuphelp',
    defaults: ['_controller' => 'Piwigo\Controller\PopuphelpController'],
    methods:  $get,
));

// ── Technical ─────────────────────────────────────────────────────────────────

// Image derivative: /i/path/to/image-thumb.jpg
$routes->add('image', new Route(
    path:         '/i/{rest}',
    defaults:     ['_controller' => 'Piwigo\Controller\ImageDerivativeController'],
    requirements: ['rest' => '.+'],
    methods:      $get,
));

// Web services: /ws  or  /ws/openapi.json  or  /ws/docs
// No leading slash before {rest} so /ws matches with rest=""
$routes->add('ws', new Route(
    path:         '/ws{rest}',
    defaults:     ['_controller' => 'Piwigo\Controller\WsController', 'rest' => ''],
    requirements: ['rest' => '(/.*)?'],
    methods:      $gp,
));

// Admin: /admin  or  /admin/batch_manager  or  /admin/categories/edit
$routes->add('admin', new Route(
    path:         '/admin{rest}',
    defaults:     ['_controller' => 'Piwigo\Controller\Admin\AdminController', 'rest' => ''],
    requirements: ['rest' => '(/.*)?'],
    methods:      $gp,
));

$routes->add('install', new Route(
    path:     '/install',
    defaults: ['_controller' => 'Piwigo\Controller\InstallController'],
    methods:  $gp,
));

$routes->add('upgrade', new Route(
    path:     '/upgrade',
    defaults: ['_controller' => 'Piwigo\Controller\UpgradeController'],
    methods:  $gp,
));

return $routes;
