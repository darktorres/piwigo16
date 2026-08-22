<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

/**
 * Typed event for the legacy `loc_end_picture` notification -- no
 * handler registered anywhere today, but no longer bare: `$imageId`
 * lets a handler scope itself to a specific picture instead of every
 * render site-wide, mirroring `Picture\Event\FilterPictureDisplayInfo`'s
 * own `$imageId` addition in this exact controller. Real caller this
 * was added for: a plugin injecting a picture-page-specific toolbar (e.g.
 * via `Template::concat()` onto a real, already-rendered Html-typed
 * template var) needs to know which picture is being viewed --
 * `Page\Event\PageHeaderRendered` itself fires too early (from the shared
 * header, before this controller builds any picture-specific data) to
 * carry it.
 */
final readonly class PicturePageRendered
{
    public function __construct(
        public int $imageId,
    ) {}
}
