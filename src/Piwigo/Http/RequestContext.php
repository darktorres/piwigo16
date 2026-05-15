<?php

declare(strict_types=1);

namespace Piwigo\Http;

/**
 * The request type the current entry-point controller is handling. Distributed
 * via {@see RequestContextRegistry}; replaces the legacy `defined('IN_ADMIN')`
 * / `defined('IN_WS')` / `defined('PHPWG_IN_UPGRADE')` checks (retired in
 * Phase 4c).
 *
 * `Gallery` is the registry's default — it covers public photo-browsing
 * routes (`GalleryController`, `PictureController`, etc.) and any path that
 * doesn't explicitly set a different context.
 */
enum RequestContext
{
    case Admin;
    case Ws;
    case Upgrade;
    case Gallery;
    case Derivative;
}
