<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * `pwg.extensions.checkUpdates`'s own update-check results (Piwigo-core
 * and per-extension outcomes) -- P25 Stage 2 item 9. Replaces
 * `$_SESSION['need_update' . AppInfo::VERSION]`/`$_SESSION[
 * 'extensions_need_update']`, session-scoped process state that made the
 * update-check operation non-idempotent (SEC-60). 86400s TTL matches
 * `Config\CurrentConfig::$updateNotifyCheckPeriod`'s own default -- the
 * established "how often to re-check for updates" cadence elsewhere in
 * this codebase (`Bootstrap\PageTail::checkForUpdates()`). Namespace/TTL
 * are supplied by config/container.php's own factory() entry, not this
 * class -- see AbstractNamedCachePool.
 */
final class ExtensionUpdateCachePool extends AbstractNamedCachePool {}
