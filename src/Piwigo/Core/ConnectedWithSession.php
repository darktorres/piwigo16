<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Core;

/**
 * Typed get/set for `$_SESSION['connected_with']` (P25 Stage 2 item 7).
 * Holds no state of its own -- every call reads/writes the superglobal
 * live -- so, like `Csrf\CsrfService`/`Auth\CookieService` elsewhere in
 * this codebase, callers construct it inline (`new
 * ConnectedWithSession()`) rather than constructor-injecting it. That
 * matters here specifically: `Ws\Server` and `Activity\ActivityService`
 * are each constructed ad-hoc (bypassing the container) in 30+ files, so
 * adding a required constructor parameter to either would cascade far
 * beyond this item's real scope for zero behavioral benefit over inline
 * construction.
 */
final class ConnectedWithSession
{
    public function get(): ?ConnectedWith
    {
        $raw = $_SESSION['connected_with'] ?? null;

        return is_string($raw) ? ConnectedWith::tryFrom($raw) : null;
    }

    public function set(ConnectedWith $value): void
    {
        $_SESSION['connected_with'] = $value->value;
    }
}
