<?php

declare(strict_types=1);

namespace Piwigo\Cache;

/**
 * No TTL -- same reasoning as ConfigCachePool: invalidation happens via a
 * changing cache key (Translator::load()'s key folds in the .po file's
 * own mtime, so an edited file busts its own entry automatically), not
 * time-based expiry. Real consumer: Piwigo\Lang\Translator::load(),
 * caching PoLoader's parsed-and-derived result -- raw PO parsing plus two
 * full passes over every translation entry cost ~18-19% of a bootstrap
 * request's server-side time with no caching at all, per a real Xdebug
 * profile. Namespace/TTL are supplied by config/container.php's own
 * factory() entry, not this class -- see AbstractNamedCachePool.
 */
final class TranslationsCachePool extends AbstractNamedCachePool {}
